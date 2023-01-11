<?php

namespace Potelo\LaravelBlockBots\Tests\Unit;

use Illuminate\Http\Request;
use Potelo\LaravelBlockBots\Tests\TestCase;
use Potelo\LaravelBlockBots\Middleware\BlockBots;
use Potelo\LaravelBlockBots\Jobs\ProcessLogWithIpInfo;

class BlockBotsMiddlewareTest extends TestCase
{
    public function test_should_block_after_limit()
    {
        $middleware = new BlockBots();
        $request = new Request();
        $this->expectsJobs(ProcessLogWithIpInfo::class);
        $limit = 100;

        for ($i = 0; $i < $limit; $i++) {
            $response = $middleware->handle($request, function () {
                return 'ok';
            }, $limit);
            $this->assertEquals('ok', $response);
        }

        $response = $middleware->handle($request, function (Request $request) {
            $this->fail('Should not be called');
        }, $limit);

        $this->assertEquals(429, $response->getStatusCode());
    }
}