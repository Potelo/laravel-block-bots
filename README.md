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


### Dynamic bot IP ranges

The default configuration includes a runtime provider alongside static ranges:

```php
'allowed_bots_by_ip' => [
    \Potelo\LaravelBlockBots\Services\BotIpProvider::class,
    'claudebot' => ['160.79.104.0/21'],
    // 'gptbot' => [], // Explicitly disable this bot's exemption.
],
```

`BotIpProvider` includes GPTBot, ChatGPT-User, OAI-SearchBot and DuckAssistBot.
Its built-in snapshots are used until each feed is fetched successfully. Successful
feeds replace the defaults entirely; later failures preserve the last successful
snapshot indefinitely. Refreshes write only to Redis, never to tracked files.
Static bot arrays always override provider results, regardless of their order.
Only an explicitly configured empty static array disables an exemption; empty
remote feeds are errors. IP-managed bots do not need an `allowed_bots` entry.

Optional configuration (URLs and defaults can be overridden or new bots added):

```php
'bot_ip_provider' => [
    'schedule_enabled' => true,
    'refresh_days' => 15,
    'redis_prefix' => 'block_bot:ip_sources:',
    'bots' => [
        'mybot' => [
            'url' => 'https://example.com/bot.json',
            'defaults' => ['192.0.2.0/24'],
        ],
    ],
],
```

Feeds must return HTTP 200 and JSON with nonempty `prefixes`, containing valid
`ipv4Prefix` and/or `ipv6Prefix` CIDRs. Invalid entries reject the entire response.
Only HTTPS endpoints are supported; redirects are rejected. Use trusted URLs.
The service class name and settings remain safe for `config:cache`; live ranges
are read from Redis at runtime.

The package automatically registers a daily scheduler check. Run Laravel's
scheduler and **an asynchronous queue worker** in the consuming application.
Each source becomes due 15 days after its last successful fetch; new sources are
due immediately. Failed sources retry five additional times, after 1 minute,
5 minutes, 30 minutes, 2 hours and 12 hours. After exhaustion the next daily check
starts a new cycle. A Redis lease prevents duplicate cycles across servers;
abandoned cycles become eligible again after its two-day expiry. Use a queue
backend that supports 12-hour delays (for example Redis or database, not SQS).

```bash
# Queue immediate refreshes, including recently updated sources:
php artisan block-bots:refresh-ips

# Queue only new or due sources (also invoked by the scheduler):
php artisan block-bots:refresh-ips --due
```

#### Verification behavior and upgrade notes

Normal guest rules and rate limits still run first. When the bot exemption is
needed, IP rules use the **original IP**, not the normalized IPv6 prefix, and run
before the shared whitelist/fake-bot sets. IP-managed bots are no longer admitted
while awaiting asynchronous DNS checks, nor do they populate permanent verification
sets. Existing queued DNS jobs for these bots exit without writing results.

Previously cached whitelist/fake-bot decisions are ignored for IP-managed requests;
no blanket deletion of shared Redis sets is performed because those sets do not
record which bot created each entry. Consequently, a removed range immediately
loses its bot exemption and a newly added range can be reconsidered on the next
request. Old shared entries retain their existing behavior for other traffic.
This also applies to static IP rules, including explicit empty overrides.

If the application already published `config/block-bots.php`, replace the four
moved static bot entries with `BotIpProvider::class` to enable dynamic updates.
Leaving a static entry in the published configuration intentionally overrides that
bot's provider ranges. Restart long-running queue workers after deployment.
