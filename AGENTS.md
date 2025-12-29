# AGENTS.md

## Project Description

**Laravel Block Bots** is a Laravel middleware package designed to protect web applications from malicious crawlers, scrapers, and high-usage offenders while allowing legitimate search engine bots (like GoogleBot, Bing, etc.) to pass through.

The package provides:
- **Ultra-fast request filtering** (less than 1ms overhead per request)
- **Reverse DNS verification** to detect fake bot user-agents
- **Rate limiting** with configurable limits (hourly, daily, weekly, monthly, annually)
- **Automatic whitelisting** of verified legitimate bots
- **Event dispatching** when users or bots are blocked
- **IP geolocation logging** via ipinfo.io integration
- **Native IPv6 support** with prefix-based rate limiting

## Architecture

```
src/
├── Abstracts/
│   └── AbstractBlockBots.php      # Base middleware class with shared logic
├── Commands/
│   ├── ClearWhitelist.php         # Artisan command to clear whitelist
│   ├── ListHits.php               # Artisan command to list hits
│   ├── ListNotified.php           # Artisan command to list notified IPs
│   └── ListWhitelist.php          # Artisan command to list whitelisted IPs
├── Contracts/
│   ├── Client.php                 # Request client wrapper (IP, user-agent, hit counting)
│   └── Configuration.php          # Configuration value object
├── Events/
│   ├── BotBlockedEvent.php        # Event fired when a bot is blocked
│   └── UserBlockedEvent.php       # Event fired when a user is blocked
├── Helpers/
│   └── IpHelper.php               # IPv6/IPv4 helper functions (prefix normalization)
├── Jobs/
│   ├── CheckIfBotIsReal.php       # Queue job for reverse DNS bot verification
│   └── ProcessLogWithIpInfo.php   # Queue job for logging with IP info
├── Middleware/
│   └── BlockBots.php              # Main middleware entry point
├── config/
│   └── block-bots.php             # Package configuration
├── views/
│   └── error.blade.php            # Blocked user view template
└── BlockBotsServiceProvider.php   # Laravel service provider
```

### Core Components

#### Middleware (`BlockBots`)
The main entry point that intercepts HTTP requests. It:
1. Checks if the feature is enabled
2. Counts request hits in Redis
3. Evaluates access rules (auth, guest, bot rules)
4. Returns 429 response or allows the request to proceed

#### Client Contract
Wraps the HTTP request to extract:
- Client IP address (original and trackable)
- User-Agent header
- Unique identifier (user ID or trackable IP)
- Hit counting with Redis TTL based on frequency

**IPv6 Support:** The Client class maintains two IP properties:
- `$ip`: The original client IP (used for DNS lookups and logging)
- `$trackableIp`: The normalized IP for rate limiting (IPv6 prefix or original IPv4)

#### IpHelper (Helpers)
Provides IPv6 and IPv4 utility functions:
- `isIPv6($ip)`: Check if an IP is IPv6
- `isIPv4($ip)`: Check if an IP is IPv4
- `normalizeIPv6ToPrefix($ip, $prefixLength)`: Normalize IPv6 to its prefix
- `getTrackableIp($ip, $prefixLength)`: Get the trackable IP (normalized prefix for IPv6, original for IPv4)
- `isSameIPv6Prefix($ip1, $ip2, $prefixLength)`: Check if two IPs share the same prefix
- `ipInCidr($ip, $cidr)`: Check if IP is within a CIDR range

#### Bot Verification (`CheckIfBotIsReal` Job)
Asynchronously verifies if a bot is legitimate using:
1. Reverse DNS lookup (`gethostbyaddr`)
2. Forward DNS verification (`gethostbyname`)
3. Hostname pattern matching against allowed bot domains
4. **IPv6-aware IP comparison** (allows same-prefix matches for large bot networks)

#### Redis Data Structures
- `block_bot:whitelist` - Set of verified legitimate IPs/prefixes
- `block_bot:fake_bots` - Set of detected fake bot IPs/prefixes
- `block_bot:pending_bots` - Set of IPs/prefixes pending verification
- `block_bot:hits:{id}` - Per-client hit counter with TTL
- `block_bot:notified:{ip}` - Per-IP/prefix notification tracking

**Note:** For IPv6 addresses, Redis keys use the normalized prefix (e.g., `2001:db8:1234:5678::`) rather than individual IPs.

## How It Works

### Request Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Incoming HTTP Request                              │
└──────────────────────────────────┬──────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     BlockBots Middleware::handle()                           │
│  1. Extract client IP and User-Agent                                         │
│  2. Normalize IPv6 to prefix (if applicable)                                 │
│  3. Increment hit counter in Redis using trackable IP                        │
└──────────────────────────────────┬──────────────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           isAllowed() Check                                  │
│  ├─ Mode = 'never' → Allow (bypass)                                         │
│  ├─ Mode = 'always' → Block                                                 │
│  ├─ Authenticated User → Check auth rules + rate limit                      │
│  └─ Guest → Check guest rules + bot rules                                   │
└──────────────────────────────────┬──────────────────────────────────────────┘
                                   │
              ┌────────────────────┴────────────────────┐
              │                                         │
              ▼                                         ▼
┌─────────────────────────┐               ┌─────────────────────────┐
│   passesBotRules()      │               │      Allowed            │
│  1. Check whitelist     │               │  → Continue Request     │
│  2. Check fake bot list │               └─────────────────────────┘
│  3. Match User-Agent    │
│  4. Dispatch verify job │
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    CheckIfBotIsReal Job (Async)                              │
│  1. Reverse DNS: gethostbyaddr(original IP) → hostname                      │
│  2. Check hostname contains allowed domain (e.g., 'google.com')             │
│  3. Forward DNS: gethostbyname(hostname) → IP                               │
│  4. Verify IP matches (exact for IPv4, prefix-aware for IPv6)               │
│  5. Add trackable IP to whitelist or fake_bots set                          │
└─────────────────────────────────────────────────────────────────────────────┘
```

### IPv6 Prefix Handling

IPv6 users typically receive a prefix (e.g., /64 or /56) from their ISP, giving them access to millions or trillions of unique IP addresses. Without proper handling:
- Rate limiting would fail as users rotate through IPs
- Bot verification would be inconsistent
- Redis storage would bloat with individual IPs

**Solution:** Normalize IPv6 addresses to their prefix for tracking:
```
2001:db8:1234:5678:aaaa:bbbb:cccc:dddd → 2001:db8:1234:5678:: (with /64 prefix)
```

This ensures all IPs within a prefix are treated as a single entity for:
- Rate limiting (hit counting)
- Whitelisting/blacklisting
- Bot verification

### Rate Limiting

The hit counter uses Redis with automatic expiration:
- **Hourly**: Expires at the next hour
- **Daily**: Expires at midnight (configurable timezone)
- **Monthly**: Expires on the first day of next month
- **Annually**: Expires on January 1st of next year

### Configuration

Key environment variables:
```env
BLOCK_BOTS_ENABLED=true
BLOCK_BOTS_MODE=production          # production|never|always
BLOCK_BOTS_USE_DEFAULT_ALLOWED_BOTS=true
BLOCK_BOTS_LOG_ENABLED=true
BLOCK_BOTS_IPV6_PREFIX_LENGTH=64    # IPv6 prefix length (64, 56, or 48)
```

Middleware usage:
```php
// In routes or middleware groups
'block:100,daily'  // 100 requests per day
'block:50,hourly'  // 50 requests per hour
```

### IPv6 Prefix Length Configuration

| Prefix | Use Case | IPs per Prefix |
|--------|----------|----------------|
| /64    | Standard residential (default) | ~18 quintillion |
| /56    | Some residential ISPs | ~4 billion /64 subnets |
| /48    | Business/enterprise | ~65,536 /64 subnets |

Choose based on your typical user base. The default `/64` is appropriate for most cases.

## Dependencies

- **PHP**: ^7.1 | ^8.0 - ^8.4
- **Laravel Framework**: ^5.5 - ^12.0
- **Redis**: Required for all data storage (via `predis/predis`)
- **Guzzle**: For ipinfo.io API calls

## Development

### Requirements
- PHP 7.1+ or 8.x
- Redis server running
- Composer

### Running Tests

```bash
# Install dependencies
composer install

# Run tests with coverage
composer test

# Or directly with PHPUnit
./vendor/bin/phpunit

# Run only IPv6-related tests
./vendor/bin/phpunit --filter IPv6
./vendor/bin/phpunit --filter IpHelper
```

### Test Configuration

Tests use Orchestra Testbench for Laravel package testing. The `phpunit.xml` configuration is provided in the project root.

### Test Coverage

The test suite includes:
- **IpHelperTest**: Unit tests for IPv6/IPv4 detection, normalization, and prefix comparison
- **BlockBotsMiddlewareTest**: Integration tests for rate limiting, including IPv6 prefix-based limiting

