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
    /** @dataProvider responseCases */
    public function test_responses($blocked, $authenticated, $json, $mode, $enabled, $expected)
    {
        Queue::fake();
        Config::set('block-bots.blocked_bots', $blocked ? ['BINGBOT'] : []);
        Config::set('block-bots.mode', $mode);
        Config::set('block-bots.enabled', $enabled);
        Config::set('block-bots.blocked_bot_message', 'Mensagem própria.');
        Auth::shouldReceive('check')->andReturn($authenticated);
        Auth::shouldReceive('guest')->andReturn(!$authenticated);
        Auth::shouldReceive('id')->andReturn(42);

        // Both exemption sources must lose to an explicit denial.
        Redis::sadd(config('block-bots.whitelist_key'), '192.0.2.1');
        Config::set('block-bots.allowed_bots_by_ip', ['bingbot' => ['192.0.2.0/24']]);
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.1',
            'HTTP_USER_AGENT' => 'Mozilla bingbot/2.0',
            'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html',
        ]);
        $response = (new BlockBots())->handle($request, function () { return 'ok'; }, 100);
        if ($expected === 200) {
            $this->assertSame('ok', $response);
        } else {
            $this->assertSame($expected, $response->getStatusCode());
            $this->assertFalse($response->headers->has('Retry-After'));
            $this->assertStringContainsString('Mensagem própria.', $json
                ? json_decode($response->getContent(), true)['message'] : $response->getContent());
        }
    }

    public function responseCases()
    {
        return [
            [true, false, true, 'production', true, 403],
            [true, false, false, 'production', true, 403],
            [true, true, true, 'production', true, 403],
            [true, false, true, 'never', true, 200],
            [true, false, true, 'production', false, 200],
            [false, true, true, 'production', true, 200],
        ];
    }

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

    public function test_reused_middleware_does_not_keep_explicit_denial()
    {
        Queue::fake();
        Config::set('block-bots.blocked_bots', ['denied']);
        $middleware = new BlockBots();
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => 'denied']);
        $this->assertSame(403, $middleware->handle($request, function () {}, 100)->getStatusCode());
        $request->headers->set('User-Agent', 'unknown');
        $response = $middleware->handle($request, function () {}, 0);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
    }
}
