<?php

namespace Potelo\LaravelBlockBots\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config;
use Potelo\LaravelBlockBots\Tests\TestCase;
use Potelo\LaravelBlockBots\Middleware\BlockBots;
use Potelo\LaravelBlockBots\Jobs\CheckIfBotIsReal;
use Potelo\LaravelBlockBots\Jobs\ProcessLogWithIpInfo;

class BlockBotsMiddlewareTest extends TestCase
{
    public function test_should_block_after_limit()
    {
        Queue::fake();
        $middleware = new BlockBots();
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '192.168.2.1');

        $limit = 10;

        for ($i = 0; $i < $limit; $i++) {
            $response = $middleware->handle($request, function () {
                return 'ok';
            }, $limit);
            $this->assertEquals('ok', $response);
        }

        $response = $middleware->handle($request, function () {
            $this->fail('Should not be called');
        }, $limit);

        $this->assertEquals(429, $response->getStatusCode());
        Queue::assertPushed(ProcessLogWithIpInfo::class, 1);
    }

    public function test_should_not_block_after_limit_if_mode_never()
    {
        Queue::fake();
        $middleware = new BlockBots();
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '192.168.2.2');

        $limit = 10;

        for ($i = 0; $i < $limit; $i++) {
            $response = $middleware->handle($request, function () {
                return 'ok';
            }, $limit);
            $this->assertEquals('ok', $response);
        }

        $response = $middleware->handle($request, function () {
            $this->fail('Should not be called');
        }, $limit);

        $this->assertEquals(429, $response->getStatusCode());

        Config::set('block-bots.mode', 'never');
        $response = $middleware->handle($request, function () {
            return 'ok';
        }, $limit);

        $this->assertEquals('ok', $response);

        Queue::assertPushed(ProcessLogWithIpInfo::class);

    }

    public function test_should_always_block_if_mode_always()
    {
        Queue::fake();
        Config::set('block-bots.mode', 'always');

        $middleware = new BlockBots();
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '192.168.2.3');

        $limit = 100;

        $response = $middleware->handle($request, function () {
            $this->fail('Should not be called');
        }, $limit);

        $this->assertEquals(429, $response->getStatusCode());
        Queue::assertPushed(ProcessLogWithIpInfo::class);
    }

    public function test_should_not_block_allowed_bot()
    {
        Queue::fake();

        $limit = 0;
        $bots = Config::get('block-bots.allowed_bots');
        $bot = $bots[reset($bots)];
        $request = new Request();
        $request->headers->set('User-Agent', $bot);
        $request->server->add(['REMOTE_ADDR' => '0.0.0.0']);

        $middleware = new BlockBots();

        $response = $middleware->handle($request, function () {
            return 'ok';
        }, $limit);

        $this->assertEquals('ok', $response);
        Queue::assertPushed(CheckIfBotIsReal::class);

        $this->assertContains('0.0.0.0', Redis::smembers(Config::get('block-bots.pending_bot_list_key')));
    }

    // ========================
    // IPv6 Integration Tests
    // ========================

    /**
     * Test that IPv6 addresses from the same /64 prefix share the same rate limit.
     * This is the core IPv6 functionality - users with millions of IPs in their
     * prefix should still be rate limited as a single entity.
     */
    public function test_should_block_ipv6_from_same_prefix_after_limit()
    {
        Queue::fake();
        Config::set('block-bots.ipv6_prefix_length', 64);

        $middleware = new BlockBots();
        $limit = 5;

        // Use different IPv6 addresses within the same /64 prefix
        $ipv6Addresses = [
            '2001:db8:1234:5678:aaaa:bbbb:cccc:0001',
            '2001:db8:1234:5678:aaaa:bbbb:cccc:0002',
            '2001:db8:1234:5678:1111:2222:3333:4444',
            '2001:db8:1234:5678:ffff:ffff:ffff:ffff',
            '2001:db8:1234:5678::1',
        ];

        // Each request from a different IP in the same prefix should count together
        foreach ($ipv6Addresses as $ip) {
            $request = new Request();
            $request->server->set('REMOTE_ADDR', $ip);

            $response = $middleware->handle($request, function () {
                return 'ok';
            }, $limit);
            $this->assertEquals('ok', $response, "Request from {$ip} should be allowed");
        }

        // The 6th request (from any IP in the same prefix) should be blocked
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '2001:db8:1234:5678:9999:8888:7777:6666');

        $response = $middleware->handle($request, function () {
            $this->fail('Should not be called - limit exceeded');
        }, $limit);

        $this->assertEquals(429, $response->getStatusCode());
    }

    /**
     * Test that IPv6 addresses from different /64 prefixes are tracked separately.
     */
    public function test_should_not_block_ipv6_from_different_prefix()
    {
        Queue::fake();
        Config::set('block-bots.ipv6_prefix_length', 64);

        $middleware = new BlockBots();
        $limit = 3;

        // Use up the limit for prefix 1
        for ($i = 0; $i < $limit; $i++) {
            $request = new Request();
            $request->server->set('REMOTE_ADDR', "2001:db8:1234:5678::$i");

            $middleware->handle($request, function () {
                return 'ok';
            }, $limit);
        }

        // Request from prefix 1 should be blocked
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '2001:db8:1234:5678::ffff');

        $response = $middleware->handle($request, function () {
            $this->fail('Should not be called');
        }, $limit);
        $this->assertEquals(429, $response->getStatusCode());

        // But request from a DIFFERENT prefix should still be allowed
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '2001:db8:1234:9999::1'); // Different 4th group

        $response = $middleware->handle($request, function () {
            return 'ok';
        }, $limit);
        $this->assertEquals('ok', $response);
    }

    /**
     * Test that IPv4 behavior remains unchanged with IPv6 support.
     */
    public function test_ipv4_behavior_unchanged()
    {
        Queue::fake();
        Config::set('block-bots.ipv6_prefix_length', 64);

        $middleware = new BlockBots();
        $limit = 3;

        // IPv4 addresses should still be tracked individually
        for ($i = 0; $i < $limit; $i++) {
            $request = new Request();
            $request->server->set('REMOTE_ADDR', '10.0.0.1');

            $response = $middleware->handle($request, function () {
                return 'ok';
            }, $limit);
            $this->assertEquals('ok', $response);
        }

        // Same IP should be blocked
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $response = $middleware->handle($request, function () {
            $this->fail('Should not be called');
        }, $limit);
        $this->assertEquals(429, $response->getStatusCode());

        // Different IPv4 should still be allowed
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '10.0.0.2');

        $response = $middleware->handle($request, function () {
            return 'ok';
        }, $limit);
        $this->assertEquals('ok', $response);
    }

    /**
     * Test that IPv6 whitelisting works with prefix normalization.
     */
    public function test_should_whitelist_ipv6_prefix()
    {
        Queue::fake();
        Config::set('block-bots.ipv6_prefix_length', 64);

        // Add an IPv6 prefix to whitelist via Redis (simulating verified bot)
        Redis::sadd(Config::get('block-bots.whitelist_key'), '2001:db8:abcd:ef01::');

        $middleware = new BlockBots();
        $limit = 0; // Limit of 0 means only whitelisted should pass

        // Any IP within the whitelisted prefix should be allowed
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '2001:db8:abcd:ef01:aaaa:bbbb:cccc:dddd');

        $response = $middleware->handle($request, function () {
            return 'ok';
        }, $limit);

        $this->assertEquals('ok', $response);
    }

    /**
     * Test that IPv6 bot detection uses trackable IP for pending list.
     */
    public function test_should_use_ipv6_prefix_for_pending_bots()
    {
        Queue::fake();
        Config::set('block-bots.ipv6_prefix_length', 64);

        $limit = 0;
        $bots = Config::get('block-bots.allowed_bots');
        $botUserAgent = array_keys($bots)[0];

        $request = new Request();
        $request->headers->set('User-Agent', $botUserAgent);
        $request->server->set('REMOTE_ADDR', '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd');

        $middleware = new BlockBots();

        $response = $middleware->handle($request, function () {
            return 'ok';
        }, $limit);

        $this->assertEquals('ok', $response);
        Queue::assertPushed(CheckIfBotIsReal::class);

        // The pending list should contain the normalized prefix, not the full IP
        $pendingList = Redis::smembers(Config::get('block-bots.pending_bot_list_key'));
        $this->assertContains('2001:db8:1234:5678::', $pendingList);
    }

    /**
     * Test /56 prefix length configuration.
     */
    public function test_ipv6_prefix_56_groups_correctly()
    {
        Queue::fake();
        Config::set('block-bots.ipv6_prefix_length', 56);

        $middleware = new BlockBots();
        $limit = 2;

        // These two IPs are different in /64 but same in /56
        $request1 = new Request();
        $request1->server->set('REMOTE_ADDR', '2001:db8:1234:5600::1');

        $request2 = new Request();
        $request2->server->set('REMOTE_ADDR', '2001:db8:1234:56ff::1'); // Different /64, same /56

        $middleware->handle($request1, fn() => 'ok', $limit);
        $middleware->handle($request2, fn() => 'ok', $limit);

        // Third request should be blocked (same /56)
        $request3 = new Request();
        $request3->server->set('REMOTE_ADDR', '2001:db8:1234:5601::1');

        $response = $middleware->handle($request3, function () {
            $this->fail('Should be blocked');
        }, $limit);

        $this->assertEquals(429, $response->getStatusCode());
    }

    /**
     * Test IPv6 localhost (::1) is handled correctly.
     */
    public function test_ipv6_localhost_handled()
    {
        Queue::fake();
        Config::set('block-bots.ipv6_prefix_length', 64);

        $middleware = new BlockBots();
        $limit = 1;

        $request = new Request();
        $request->server->set('REMOTE_ADDR', '::1');

        $response = $middleware->handle($request, function () {
            return 'ok';
        }, $limit);

        $this->assertEquals('ok', $response);
    }
}

