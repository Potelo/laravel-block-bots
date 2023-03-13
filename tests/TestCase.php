<?php

namespace Potelo\LaravelBlockBots\Tests;

use Illuminate\Support\Facades\Redis;

class TestCase extends \Orchestra\Testbench\TestCase
{

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Potelo\LaravelBlockBots\BlockBotsServiceProvider::class,
        ];
    }
}