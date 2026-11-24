<?php

namespace Potelo\LaravelBlockBots\Services;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Jobs\RefreshBotIpRanges;

class BotIpProvider
{
    public function sources(): array
    {
        $defaults = require __DIR__ . '/bot-ip-defaults.php';
        $sources = [];
        foreach ([
            'gptbot' => 'https://openai.com/gptbot.json',
            'chatgpt-user' => 'https://openai.com/chatgpt-user.json',
            'oai-searchbot' => 'https://openai.com/searchbot.json',
            'duckassistbot' => 'https://duckduckgo.com/duckassistbot.json',
        ] as $bot => $url) {
            $sources[$bot] = ['url' => $url, 'defaults' => $defaults[$bot]];
        }
        foreach (config('block-bots.bot_ip_provider.bots', []) as $bot => $source) {
            $bot = strtolower($bot);
            $sources[$bot] = array_merge($sources[$bot] ?? ['defaults' => []], $source);
        }
        return $sources;
    }

    public function key(string $bot): string
    {
        return config('block-bots.bot_ip_provider.redis_prefix', 'block_bot:ip_sources:')
            . '{bot:' . rawurlencode($bot) . '}';
    }

    public function snapshot(string $bot): ?array
    {
        $value = Redis::get($this->key($bot));
        return $value ? json_decode($value, true) : null;
    }

    public function ranges(string $bot): array
    {
        $snapshot = $this->snapshot($bot);
        return $snapshot['ranges'] ?? $this->sources()[$bot]['defaults'] ?? [];
    }

    public function getRanges(): array
    {
        $ranges = [];
        foreach (array_keys($this->sources()) as $bot) {
            $ranges[$bot] = $this->ranges($bot);
        }
        return $ranges;
    }

    public function queueRefresh(string $bot, bool $force = false): bool
    {
        if (!$force && !$this->isRefreshDue($bot)) {
            return false;
        }

        $token = bin2hex(random_bytes(16));
        // Hold the lease across all delayed attempts; recover abandoned cycles after two days.
        if (!Redis::set($this->key($bot) . ':lock', $token, 'EX', 172800, 'NX')) {
            return false;
        }

        try {
            // Another cycle may have finished between the first read and acquiring the lease.
            if (!$force && !$this->isRefreshDue($bot)) {
                $this->release($bot, $token);
                return false;
            }

            RefreshBotIpRanges::dispatch($bot, $token);
        } catch (\Throwable $exception) {
            $this->release($bot, $token);
            throw $exception;
        }
        return true;
    }

    private function isRefreshDue(string $bot): bool
    {
        $snapshot = $this->snapshot($bot);
        $interval = max(1, (int) config('block-bots.bot_ip_provider.refresh_days', 15)) * 86400;

        return !$snapshot || $snapshot['updated_at'] + $interval <= Carbon::now()->timestamp;
    }

    public function ownsLease(string $bot, string $token): bool
    {
        return Redis::get($this->key($bot) . ':lock') === $token;
    }

    public function release(string $bot, string $token)
    {
        Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1,
            $this->key($bot) . ':lock', $token);
    }

    public function refresh(string $bot, string $token)
    {
        $source = $this->sources()[$bot] ?? null;
        if (!$source || strtolower(parse_url($source['url'] ?? '', PHP_URL_SCHEME) ?? '') !== 'https') {
            throw new \InvalidArgumentException('Bot feed must have a configured HTTPS URL: ' . $bot);
        }
        // http_errors=false: every non-200 status goes through the check below instead of Guzzle exceptions.
        $response = app(Client::class)->request('GET', $source['url'], [
            'connect_timeout' => 5,
            'timeout' => 20,
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \UnexpectedValueException('Bot feed did not return HTTP 200: ' . $bot);
        }
        $ranges = $this->parse((string) $response->getBody());
        $value = json_encode(['ranges' => $ranges, 'updated_at' => Carbon::now()->timestamp]);
        // Publish the whole snapshot atomically, only if this cycle still owns the lease.
        Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then redis.call('set', KEYS[2], ARGV[2]); redis.call('del', KEYS[1]); return 1 end return 0", 2,
            $this->key($bot) . ':lock', $this->key($bot), $token, $value);
    }

    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['prefixes']) || !is_array($data['prefixes'])) {
            throw new \UnexpectedValueException('Invalid or empty bot IP feed.');
        }
        $ranges = [];
        foreach ($data['prefixes'] as $entry) {
            if (!is_array($entry) || (!isset($entry['ipv4Prefix']) && !isset($entry['ipv6Prefix']))) {
                throw new \UnexpectedValueException('Missing bot IP prefix.');
            }
            foreach (['ipv4Prefix' => 32, 'ipv6Prefix' => 128] as $field => $max) {
                if (!array_key_exists($field, $entry)) {
                    continue;
                }
                $cidr = $entry[$field];
                if (!is_string($cidr) || !preg_match('~^([^/]+)/([0-9]+)$~D', $cidr, $parts)
                    || filter_var($parts[1], FILTER_VALIDATE_IP, $max === 32 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) === false
                    || (int) $parts[2] > $max) {
                    throw new \UnexpectedValueException('Invalid bot IP CIDR.');
                }
                $ranges[] = $cidr;
            }
        }
        return array_values(array_unique($ranges));
    }
}
