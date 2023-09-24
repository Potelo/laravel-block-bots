<?php

namespace Potelo\LaravelBlockBots;

use Illuminate\Support\ServiceProvider;
use Potelo\LaravelBlockBots\Commands\ClearWhitelist;
use Potelo\LaravelBlockBots\Commands\ListWhitelist;
use Potelo\LaravelBlockBots\Commands\ListHits;
use Potelo\LaravelBlockBots\Commands\ListNotified;

class BlockBotsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearWhitelist::class,
                ListWhitelist::class,
                ListHits::class,
                ListNotified::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/config/block-bots.php' => config_path('block-bots.php'),
        ], 'config');

        $this->loadViewsFrom(__DIR__ . '/views', 'block-bots');

        $this->publishes([
            __DIR__ . '/views' => resource_path('views/vendor/block-bots'),
        ]);
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/block-bots.php',
            'block-bots'
        );
    }
}
