<?php

namespace Potelo\LaravelBlockBots\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Potelo\LaravelBlockBots\Helpers\IpHelper;

class CheckIfBotIsReal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $client;
    protected $options;
    protected $allowedBots;


    /**
     * Checks whether the given IP address really belongs to a valid host or not
     *
     * @param $client $client->ip the IP address to check
     * @param $allowedBots
     * @param $options
     */
    public function __construct($client, $allowedBots, $options)
    {
        $this->client = $client;
        $this->allowedBots = $allowedBots;
        $this->options = $options;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Jobs queued before an upgrade must not repopulate permanent verification sets.
        if (app(\Potelo\LaravelBlockBots\Services\BotIpResolver::class)
            ->match((string) $this->client->userAgent) !== null) {
            Redis::srem($this->options->pending_bot_list_key, $this->client->trackableIp ?? $this->client->ip);
            return;
        }

        $found_bot_key = null;

        foreach (array_keys($this->allowedBots) as $bot) {
            if (strpos(strtolower($this->client->userAgent), strtolower($bot)) !== false) {
                $found_bot_key = $bot;
                break;
            }
        }

        if (is_null($found_bot_key)) {
            $ip = !empty($this->client) && !empty($this->client->ip) ? ", IP: {$this->client->ip}." : ". The IP was not recovered.";

            throw new \InvalidArgumentException("I did not found \"{$this->client->userAgent}\" key{$ip}");
        }

        // Use trackable IP (normalized IPv6 prefix) for Redis operations
        $trackableIp = $this->client->trackableIp ?? $this->client->ip;

        // Remove from the pending list
        Redis::srem($this->options->pending_bot_list_key, $trackableIp);
        
        if ($this->isValid($found_bot_key)) {
            // Add to whitelist using trackable IP (entire prefix gets whitelisted)
            Redis::sadd($this->options->whitelist_key, $trackableIp);

            if ($this->options->log) {
                ProcessLogWithIpInfo::dispatch($this->client, 'GOOD_CRAWLER', $this->options);
            }
        } else {
            // Add to fake bot list using trackable IP
            Redis::sadd($this->options->fake_bot_list_key, $trackableIp);

            if ($this->options->log) {
                ProcessLogWithIpInfo::dispatch($this->client, 'BAD_CRAWLER', $this->options);
            }
        }
    }

    /**
     * Validate if the bot is legitimate using reverse DNS verification.
     *
     * Forward DNS must resolve to the original address, including IPv6.
     *
     * @param string $found_bot_key
     * @return bool
     */
    private function isValid($found_bot_key)
    {
        // Use original IP for validation
        $originalIp = $this->client->ip;
        
        if (filter_var($originalIp, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Check if bot has IP ranges configured (takes priority over DNS)
        $ipRanges = $this->options->allowed_bots_by_ip[strtolower($found_bot_key)] ?? null;
        if (!empty($ipRanges) && is_array($ipRanges)) {
            return IpHelper::ipInAnyCidr($originalIp, $ipRanges);
        }

        $valid_host = strtolower($this->allowedBots[$found_bot_key]);

        // A wildcard will enable this user-agent to any IP
        if ($valid_host === '*') {
            return true;
        }

        // Perform reverse DNS lookup using original IP
        $host = rtrim(strtolower($this->resolveHostname($originalIp)), '.');
        
        // Check if the hostname matches the expected domain
        $valid_host = rtrim($valid_host, '.');
        $hostIsValid = strpos($valid_host, '.') !== false
            ? ($host === $valid_host || substr($host, -strlen('.' . $valid_host)) === '.' . $valid_host)
            : strpos($host, $valid_host) !== false;
        if (!$hostIsValid) {
            return false;
        }

        if (IpHelper::isIPv4($originalIp)) {
            return $this->resolveIpv4($host) === $originalIp;
        }

        foreach ($this->resolveIpv6($host) as $ip) {
            if (inet_pton($ip) === inet_pton($originalIp)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveHostname($ip)
    {
        return gethostbyaddr($ip);
    }

    protected function resolveIpv4($host)
    {
        return gethostbyname($host);
    }

    protected function resolveIpv6($host)
    {
        $records = dns_get_record($host, DNS_AAAA);
        if ($records === false) {
            // Transient DNS failures must not permanently classify a bot as fake.
            throw new \RuntimeException('Unable to resolve AAAA records for ' . $host);
        }

        return array_column($records, 'ipv6');
    }
}
