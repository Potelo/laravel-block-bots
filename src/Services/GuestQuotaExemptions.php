<?php

namespace Potelo\LaravelBlockBots\Services;

use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Jobs\VerifyGuestCrawler;
use Symfony\Component\HttpFoundation\IpUtils;

class GuestQuotaExemptions
{
    /** Explicit CIDRs are administrative exceptions, never an assertion of crawler identity. */
    public static function allows($client, $options, array $allowedBots): bool
    {
        if (filter_var($client->ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return IpUtils::checkIp($client->ip, (array) $options->whitelist_ips)
            || self::verified($client, $options, $allowedBots, false);
    }

    public static function isVerifiedSearchCrawler($client, $options, array $allowedBots): bool
    {
        return filter_var($client->ip, FILTER_VALIDATE_IP) !== false
            && self::verified($client, $options, $allowedBots, true);
    }

    private static function verified($client, $options, array $allowedBots, bool $searchOnly): bool
    {
        $agent = (string) $client->userAgent;
        if ($agent === '' || (!$options->use_default_allowed_bots && !$allowedBots)) {
            return false;
        }

        $resolver = app(BotIpResolver::class);
        $domains = (array) $options->guest_quota_dns_domains;
        $providers = $resolver->bots();
        $candidates = array_unique(array_merge($providers, array_keys($domains)));
        $matched = [];
        foreach ($candidates as $name) {
            if ($name !== '' && stripos($agent, $name) !== false) {
                $matched[] = $name;
            }
        }

        // A mixed identity cannot borrow another crawler's verification.
        if (count($matched) !== 1) {
            return false;
        }
        $bot = $matched[0];
        if ($searchOnly && !in_array($bot, ['googlebot', 'bingbot'], true)) {
            return false;
        }
        if ($searchOnly) {
            foreach (array_keys($allowedBots) as $alias) {
                if ($alias !== '' && stripos($agent, $alias) !== false && stripos($bot, $alias) === false) {
                    return false;
                }
            }
        }
        if (!$options->use_default_allowed_bots && !array_key_exists($bot, $allowedBots)) {
            return false;
        }

        if (in_array($bot, $providers, true)) {
            // An explicit empty list disables this identity; never fall back to DNS.
            return IpUtils::checkIp($client->ip, $resolver->match($bot) ?? []);
        }

        $suffixes = [];
        foreach ((array) $domains[$bot] as $domain) {
            $domain = strtolower(rtrim((string) $domain, '.'));
            if (strpos($domain, '.') !== false && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                $suffixes[] = $domain;
            }
        }
        if (!$suffixes) {
            return false;
        }

        return self::dnsVerdict($client->ip, $bot, $suffixes);
    }

    private static function dnsVerdict(string $ip, string $bot, array $domains): bool
    {
        $address = inet_pton($ip);
        $network = bin2hex(substr($address, 0, strlen($address) === 4 ? 3 : 6));
        $prefix = 'block_bot:guest_dns:{' . $network . '}:';
        $key = $prefix . hash('sha256', $address . "\0" . $bot . "\0" . implode("\0", $domains));
        $value = Redis::get($key);
        if ($value !== null && $value !== false) {
            return $value === 'verified';
        }

        // A sync queue would perform network I/O on web workers.
        $connection = config('queue.default');
        if (!in_array(config('queue.connections.' . $connection . '.driver'), ['redis', 'database', 'sqs', 'beanstalkd'], true)) {
            return false;
        }

        // Bound DNS jobs from rotating addresses; both keys hash to one Redis Cluster slot.
        $lease = bin2hex(random_bytes(16));
        $claim = <<<'LUA'
if redis.call('exists', KEYS[1]) == 1 then return 0 end
local n = tonumber(redis.call('get', KEYS[2]) or '0')
if n >= 32 then return 0 end
if redis.call('incr', KEYS[2]) == 1 then redis.call('expire', KEYS[2], 60) end
redis.call('set', KEYS[1], ARGV[1], 'EX', 300)
return 1
LUA;
        if (Redis::eval($claim, 2, $key, $prefix . 'jobs', $lease)) {
            try {
                VerifyGuestCrawler::dispatch(inet_ntop($address), $domains, $key, $lease);
            } catch (\Throwable $exception) {
                // Keep the short lease if the queue is down, and keep the guest quota in force.
                report($exception);
            }
        }
        return false;
    }
}
