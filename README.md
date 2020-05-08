# Laravel Block Bots

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

## Introduction

Laravel Block bots is a pacakge that block bad crawlers, people trying to scrape your website or high-usage users, but lets good and important crawlers such as GoogleBot and Bing pass-thu.

## Features

- ULTRA fast, less than 1ms increase in each request.
- Verify Crawlers using reverse DNS
- Highly configurable
- Redirect users to a page when they got blocked
- Allow Logged users to always bypass blocks

## Install

Via Composer

```bash
composer require potelo/laravel-block-bots
```

#### Requirement

- This package rely on heavly on Redis. To use it, make sure that Redis is configured and ready. (see [Laravel Redis Configuration](https://laravel.com/docs/5.6/redis#configuration))

#### Before Laravel 5.5

In Laravel 5.4. you'll manually need to register the `\Potelo\LaravelBlockBots\BlockBots::class` service provider in `config/app.php`.

#### Config

To adjust the library, you can publish the config file to your project using:

```
php artisan vendor:publish --provider="Potelo\LaravelBlockBots\BlockBotsServiceProvider"
```

Configure variables in your .env file:

```
BLOCK_BOTS_ENABLED=true // Enables block bots
BLOCK_BOTS_MODE=production // options: `production` (like a charm), `never` (bypass every route), `always` (blocks every routes)
BLOCK_BOTS_USE_DEFAULT_ALLOWED_BOTS=true // if you want to use our preseted whitelist
BLOCK_BOTS_WHITELIST_KEY=block_bot:whitelist // key for whitelist in Redis
BLOCK_BOTS_FAKE_BOTS_KEY=block_bot:fake_bots // key for fake bots in Redis
BLOCK_BOTS_PENDING_BOTS_KEY=block_bot:pending_bots // key for pending bots in Redis
BLOCK_BOTS_LOG_ENABLED=true // Enables log

```

## Usage

It's simple. Go to `Kernel.php` and add to the `$routeMiddleware` block as :

```
protected $routeMiddleware = [
        ...
        'block' => \Potelo\LaravelBlockBots\Middleware\BlockBots::class,
    ];
```

Than you can put in the desired groups. For exemple, lets set to the Wrb group:

```

 protected $middlewareGroups = [
        'web' => [
            ...
            \App\Http\Middleware\VerifyCsrfToken::class,
            'block:100,/limit'
        ],
```

Where:

- **100**: is the number of pages an IP can access every day
- **/limit**: Is the route we going to redirect the IP after the limit

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Credits

- [Potelo][link-author]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/potelo/laravel-block-bots.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/potelo/laravel-block-bots.svg?style=flat-square
[link-packagist]: https://packagist.org/packages/potelo/laravel-block-bots
[link-downloads]: https://packagist.org/packages/potelo/laravel-block-bots
[link-author]: https://github.com/potelo
