# Laravel Block Bots

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Total Downloads][ico-downloads]][link-downloads]

## Introduction

Laravel Block bots is a package that blocks bad crawlers, people trying to scrape your website or high-usage users, but lets good and important crawlers such as GoogleBot and Bing pass through.

## Features

- ULTRA fast, less than 1ms increase in each request.
- Verify Crawlers using reverse DNS
- Highly configurable
- Redirect users to a page when they got blocked
- Allow Logged users to always bypass blocks
- **Native IPv6 support** with prefix-based rate limiting

## Install

Via Composer

```bash
composer require potelo/laravel-block-bots
```

#### Requirement

- This package relies heavily on Redis. To use it, make sure that Redis is configured and ready. (see [Laravel Redis Configuration](https://laravel.com/docs/5.6/redis#configuration))

#### Before Laravel 5.5

In Laravel 5.4, you'll manually need to register the `\Potelo\LaravelBlockBots\BlockBots::class` service provider in `config/app.php`.

#### Config

To adjust the library, you can publish the config file to your project using:

```
php artisan vendor:publish --provider="Potelo\LaravelBlockBots\BlockBotsServiceProvider"
```

Configure variables in your .env file:

```
BLOCK_BOTS_ENABLED=true                     # Enables block bots
BLOCK_BOTS_MODE=production                  # Options: production, never, always
BLOCK_BOTS_USE_DEFAULT_ALLOWED_BOTS=true    # Use our preset whitelist
BLOCK_BOTS_WHITELIST_KEY=block_bot:whitelist
BLOCK_BOTS_FAKE_BOTS_KEY=block_bot:fake_bots
BLOCK_BOTS_PENDING_BOTS_KEY=block_bot:pending_bots
BLOCK_BOTS_LOG_ENABLED=true                 # Enables log
BLOCK_BOTS_IPV6_PREFIX_LENGTH=64            # IPv6 prefix length (see below)
```

## Usage

It's simple. Go to `Kernel.php` and add to the `$routeMiddleware` block as:

```php
protected $routeMiddleware = [
    // ...
    'block' => \Potelo\LaravelBlockBots\Middleware\BlockBots::class,
];
```

Then you can put it in the desired groups. For example, let's set it to the web group:

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \App\Http\Middleware\VerifyCsrfToken::class,
        'block:100,daily', // 100 requests per day.
    ],
];
```

Where:

- **100**: is the number of pages an IP can access every day
- **daily**: is the time period. Options: `hourly`, `daily`, `weekly`, `monthly`, `annually`

## IPv6 Support

This package has native IPv6 support with **prefix-based rate limiting**.

### Why Prefix-Based Rate Limiting?

IPv6 users typically receive a prefix (e.g., /64) from their ISP, giving them access to millions or trillions of unique IP addresses. Without prefix-based handling, a malicious user could easily bypass rate limits by rotating through different IPs within their allocation.

This package normalizes IPv6 addresses to their prefix for tracking:

```
2001:db8:1234:5678:aaaa:bbbb:cccc:dddd → 2001:db8:1234:5678::
```

All IPs within the same prefix are treated as a single entity for rate limiting.

### Configuring IPv6 Prefix Length

Set the prefix length in your `.env`:

```
BLOCK_BOTS_IPV6_PREFIX_LENGTH=64
```

| Prefix | Use Case | Description |
|--------|----------|-------------|
| /64    | Standard residential (default) | Most common allocation, recommended for most cases |
| /56    | Some residential ISPs | If your users typically have /56 allocations |
| /48    | Business/enterprise | For sites primarily serving business networks |

### Bot Verification with IPv6

The package also handles legitimate bots (like GoogleBot) that use IPv6:
- Original IP is used for reverse DNS verification
- Trackable prefix is used for whitelisting
- Once verified, all IPs within the bot's prefix are automatically allowed

This ensures legitimate crawlers are never blocked while still rate limiting potential abusers.

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

