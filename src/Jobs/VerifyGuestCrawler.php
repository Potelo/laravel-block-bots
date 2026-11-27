<?php

namespace Potelo\LaravelBlockBots\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Redis;

class VerifyGuestCrawler implements ShouldQueue
{
    use Dispatchable, Queueable;

    public $tries = 1;
    public $timeout = 20;

    private $ip;
    private $domains;
    private $key;
    private $lease;

    public function __construct(string $ip, array $domains, string $key, string $lease)
    {
        $this->ip = $ip;
        $this->domains = $domains;
        $this->key = $key;
        $this->lease = $lease;
    }

    public function handle()
    {
        if (Redis::get($this->key) !== $this->lease) {
            return;
        }
        $verified = $this->verify();
        // An old or delayed job cannot overwrite a newer verdict.
        Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('setex', KEYS[1], ARGV[2], ARGV[3]) end return 0",
            1, $this->key, $this->lease, $verified ? 21600 : 300, $verified ? 'verified' : 'denied');
    }

    private function verify(): bool
    {
        $host = strtolower(rtrim((string) $this->reverse($this->ip), '.'));
        foreach ($this->domains as $suffix) {
            if ($host !== $suffix && substr($host, -strlen('.' . $suffix)) !== '.' . $suffix) {
                continue;
            }

            $packed = inet_pton($this->ip);
            foreach ($this->forward($host, strlen($packed) === 4) as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) && inet_pton($address) === $packed) {
                    return true;
                }
            }
            break;
        }
        return false;
    }

    protected function reverse(string $ip)
    {
        return gethostbyaddr($ip);
    }

    protected function forward(string $host, bool $ipv4): array
    {
        $records = dns_get_record($host . '.', $ipv4 ? DNS_A : DNS_AAAA);
        return array_column($records ?: [], $ipv4 ? 'ip' : 'ipv6');
    }
}
