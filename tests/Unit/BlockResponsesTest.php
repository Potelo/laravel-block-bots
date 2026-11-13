<?php

namespace Potelo\LaravelBlockBots\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Middleware\BlockBots;
use Potelo\LaravelBlockBots\Tests\TestCase;

class BlockResponsesTest extends TestCase
{
    /** @dataProvider limitCases */
    public function test_retry_after_uses_counter_ttl($authenticated, $json, $fake, $ttl)
    {
        Queue::fake();
        Config::set('block-bots.log', false);
        Config::set('block-bots.allowed_bots_by_ip', []);
        Auth::shouldReceive('check')->andReturn($authenticated);
        Auth::shouldReceive('guest')->andReturn(!$authenticated);
        Auth::shouldReceive('id')->andReturn(42);
        $key = 'block_bot:hits:' . ($authenticated ? '42' : '192.0.2.10');
        Redis::set($key, 10);
        if ($ttl > 0) {
            Redis::expire($key, $ttl);
        }
        if ($fake) {
            Redis::sadd(config('block-bots.fake_bot_list_key'), '192.0.2.10');
        }
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => $fake ? 'googlebot' : 'UnknownCrawler',
            'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html',
        ]);
        $response = (new BlockBots())->handle($request, function () {
            $this->fail('Exceeded limit');
        }, 1);
        $this->assertSame(429, $response->getStatusCode());
        if ($ttl > 0) {
            $actual = (int) $response->headers->get('Retry-After');
            $this->assertGreaterThanOrEqual($ttl - 1, $actual);
            $this->assertLessThanOrEqual($ttl, $actual);
        } else {
            $this->assertFalse($response->headers->has('Retry-After'));
        }
    }

    public function limitCases()
    {
        return [
            [false, true, false, 125],
            [false, false, false, 125],
            [true, true, false, 125],
            [false, true, true, 125],
            [false, true, false, -1],
        ];
    }

    public function test_custom_denial_below_limit_has_no_retry_after()
    {
        Queue::fake();
        Config::set('block-bots.mode', 'always');
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $response = (new BlockBots())->handle($request, function () { return 'ok'; }, 100);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Retry-After'));
    }

}
