<?php

namespace Potelo\LaravelBlockBots;

use Illuminate\Support\ServiceProvider;

class BlockBotsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/block-bots.php' => config_path('block-bots.php'),
        ]);

        $this->loadViewsFrom(__DIR__.'/views', 'block-bots');

        $this->publishes([
            __DIR__.'/views' => resource_path('views/vendor/block-bots'),
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
            __DIR__.'/config/block-bots.php', 'block-bots'
        );
    }
}
