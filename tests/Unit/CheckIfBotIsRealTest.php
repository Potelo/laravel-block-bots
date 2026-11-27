<?php

namespace Potelo\LaravelBlockBots\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Contracts\Client;
use Potelo\LaravelBlockBots\Contracts\Configuration;
use Potelo\LaravelBlockBots\Jobs\CheckIfBotIsReal;
use Potelo\LaravelBlockBots\Tests\TestCase;

class CheckIfBotIsRealTest extends TestCase
{
    /** @dataProvider dnsCases */
    public function test_dns_verification($agent, $domain, $ip, $ptr, $forward, $valid)
    {
        Config::set('block-bots.log', false);
        Config::set('block-bots.allowed_bots_by_ip', []);
        $client = new Client(Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent,
        ]));
        $bots = $domain === null ? config('block-bots.allowed_bots') : [$agent => $domain];
        $job = new class($client, $bots, new Configuration()) extends CheckIfBotIsReal {
            public $ptr;
            public $forward;
            protected function resolveHostname($ip) { return $this->ptr; }
            protected function resolveIpv4($host) { return $this->forward; }
            protected function resolveIpv6($host) {
                if ($this->forward instanceof \Exception) { throw $this->forward; }
                return $this->forward;
            }
        };
        $job->ptr = $ptr;
        $job->forward = $forward;
        Redis::sadd(config('block-bots.pending_bot_list_key'), $client->trackableIp);
        try {
            $job->handle();
            $this->assertNotNull($valid, 'DNS failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertNull($valid);
        }
        $this->assertSame($valid === true ? 1 : 0, (int) Redis::sismember(config('block-bots.whitelist_key'), $client->trackableIp));
        $this->assertSame($valid === false ? 1 : 0, (int) Redis::sismember(config('block-bots.fake_bot_list_key'), $client->trackableIp));
        $this->assertSame(0, (int) Redis::sismember(config('block-bots.pending_bot_list_key'), $client->trackableIp));
    }

    public function dnsCases()
    {
        return [
            ['bingbot', 'msn.com', '192.0.2.1', 'Crawler.Search.MSN.COM.', '192.0.2.1', true],
            ['bingbot', 'msn.com', '192.0.2.1', 'msn.com', '192.0.2.1', true],
            ['bingbot', 'msn.com', '192.0.2.1', 'msn.com.attacker.test', '192.0.2.1', false],
            ['bingbot', 'msn.com', '192.0.2.1', 'evilmsn.com', '192.0.2.1', false],
            ['bingbot', 'msn.com', '192.0.2.1', 'search.msn.com', '192.0.2.2', false],
            ['msnbot', null, '192.0.2.1', 'msn.attacker.test', '192.0.2.1', false],
            ['msnbot', null, '192.0.2.1', 'search.msn.com', '192.0.2.1', true],
            ['googlebot', 'google', '192.0.2.1', 'crawl.googlebot.com', '192.0.2.1', true],
            ['custom', '*', '192.0.2.1', '', '', true],
            ['bingbot', 'msn.com', '2001:db8::1', 'search.msn.com', ['2001:0db8:0:0:0:0:0:1'], true],
            ['bingbot', 'msn.com', '2001:db8::1', 'search.msn.com', ['2001:db8::2'], false],
            ['bingbot', 'msn.com', '2001:db8::1', 'search.msn.com', [], false],
            ['bingbot', 'msn.com', '2001:db8::1', 'search.msn.com', new \RuntimeException('DNS unavailable'), null],
        ];
    }
}
