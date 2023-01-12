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
}