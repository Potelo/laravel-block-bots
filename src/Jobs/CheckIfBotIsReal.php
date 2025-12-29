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
     * For IPv6 addresses, this uses the original IP for DNS lookups but
     * compares the result considering that the forward DNS might return
     * a different IP within the same prefix.
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
        $host = strtolower(gethostbyaddr($originalIp));
        
        // Check if the hostname matches the expected domain
        $hostIsValid = (strpos($host, $valid_host) !== false);
        if (!$hostIsValid) {
            return false;
        }

        // Perform forward DNS lookup
        $ipAfterLookup = gethostbyname($host);
        
        // For IPv4: exact match required
        if (IpHelper::isIPv4($originalIp)) {
            return $ipAfterLookup === $originalIp;
        }
        
        // For IPv6: the forward DNS might return a different IP within the same prefix
        // (e.g., large bots may use multiple IPs within their allocated prefix)
        if (IpHelper::isIPv6($originalIp)) {
            // First, try exact match
            if ($ipAfterLookup === $originalIp) {
                return true;
            }
            
            // If forward DNS returned an IPv6, check if it's in the same prefix
            if (IpHelper::isIPv6($ipAfterLookup)) {
                $prefixLength = $this->options->ipv6_prefix_length ?? 64;
                return IpHelper::isSameIPv6Prefix($originalIp, $ipAfterLookup, $prefixLength);
            }
            
            // Forward DNS returned IPv4 for an IPv6 request - this is unusual
            // Some hosts have both A and AAAA records, so we allow it if hostIsValid
            return true;
        }

        // Fallback: exact match for any other case
        return $ipAfterLookup === $originalIp;
    }
}

