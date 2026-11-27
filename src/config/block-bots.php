<?php

return [

    /*
     * Enable / disable firewall
     *
     */

    'enabled' => env('BLOCK_BOTS_ENABLED', true),

    'mode' => env('BLOCK_BOTS_MODE', 'production'),

    'use_default_allowed_bots' => env('BLOCK_BOTS_USE_DEFAULT_ALLOWED_BOTS', true),

    // 'block_when_crash' => env('BLOCK_BOTS_BLOCK_WHEN_CRASH', true),


    'whitelist_key' => env('BLOCK_BOTS_WHITELIST_KEY', "block_bot:whitelist"),

    'fake_bot_list_key' => env('BLOCK_BOTS_FAKE_BOTS_KEY', "block_bot:fake_bots"),

    'pending_bot_list_key' => env('BLOCK_BOTS_PENDING_BOTS_KEY', "block_bot:pending_bots"),


    'ip_info_key' => env('BLOCK_BOTS_IP_INFO_KEY', null),

    /*
     * IPv6 prefix length for rate limiting and tracking.
     * 
     * IPv6 users are typically assigned a prefix from their ISP, giving them
     * millions of unique IP addresses. This setting groups all IPs within 
     * the same prefix for rate limiting purposes.
     *
     * Common values:
     * - 64: Standard /64 allocation (most residential ISPs)
     * - 56: Some ISPs allocate /56 to residential users
     * - 48: Business/enterprise allocations
     *
     * Smaller number = larger prefix = more IPs grouped together
     * Default: 64 (most common residential allocation)
     */
    'ipv6_prefix_length' => env('BLOCK_BOTS_IPV6_PREFIX_LENGTH', 64),

    /*
     * Whitelisted IP addresses.
     * Supports both IPv4 and IPv6 addresses, including CIDR notation.
     * Examples:
     *      '127.0.0.1',
     *      '192.168.0.0/16',
     *      '::1',
     *      '2001:db8::/32',
     */

    'whitelist_ips' => [
        '127.0.0.1',
        '::1'
    ],

    /*
     * Log channel
     */
    'channels_info' => [
        'single'
    ],

    /*
     * This Log channel will receive when a bad crawler is detected or someone is banned
     */
    'channels_blocks' => [
        'single'
    ],

    /*
     * Send suspicious events to log?
     *
     */

    'log' => env('BLOCK_BOTS_LOG_ENABLED', env('BLOCK_BOTS_LOG_BLOCKED_REQUESTS', true)),
    'log_only_guest' => env('BLOCK_BOTS_LOG_ONLY_GUEST', true),

    /*
     * The list of allowed user-agents. The value of the key should be a keyword in hostname or * for enable to everyone
     *
     * For bots that support reverse DNS verification, use the expected hostname.
     * For bots without DNS verification or IP lists, use '*' to allow all IPs with that user-agent.
     */
    'allowed_bots' => [
        'ahrefs' => 'ahrefs',
        'applebot' => 'apple',
        'alexa' => 'alexa',
        'ask' => 'ask',
        'baidu' => 'baidu',
        'bing' => 'msn.com',
        'duckduck' => '*',
        'exabot' => 'exabot',
        'facebook' => 'facebook',
        'google' => 'google',
        'msn' => 'msn.com',
        'msnbot' => 'msn.com',
        'sogou' => 'sogou',
        'soso' => 'soso',
        'twitter' => 'twitter',
        'yahoo' => 'yahoo',
        'yandex' => 'yandex',
    ],

    /*
     * Allowed bots by IP range.
     *
     * For bots that publish official IP ranges but don't support reverse DNS verification,
     * map the user-agent keyword to an array of CIDR ranges.
     *
     * IP rules grant a bot exemption only when the original IP matches a range.
     * When default bots are disabled, the bot must also match the middleware's custom list.
     * Explicit arrays override provider results, including [] to disable an exemption.
     * IP rules are checked directly before shared verification sets; no DNS fallback.
     */
    'allowed_bots_by_ip' => [
        \Potelo\LaravelBlockBots\Services\BotIpProvider::class,
        'claudebot' => ['160.79.104.0/21'],
    ],

    'bot_ip_provider' => [
        'schedule_enabled' => true,
        'refresh_days' => 15,
        'redis_prefix' => 'block_bot:ip_sources:',
        /*
         * Bots whose IP ranges are fetched from an official JSON feed.
         * Format: 'user-agent keyword' => ['url' => 'https://...', 'defaults' => [...]].
         * 'defaults' (optional) are CIDRs used until the first successful refresh;
         * without them the bot is not exempted until its feed is fetched.
         * Remove an entry to stop managing that bot.
         */
        'bots' => [
            'gptbot' => ['url' => 'https://openai.com/gptbot.json'],
            'chatgpt-user' => ['url' => 'https://openai.com/chatgpt-user.json'],
            'oai-searchbot' => ['url' => 'https://openai.com/searchbot.json'],
            'duckassistbot' => ['url' => 'https://duckduckgo.com/duckassistbot.json'],
        ],
    ],

    /*
     * Default json response when blocked
     */
    'json_response' => [
        'message' => 'Você está acima do limite.'
    ],

    /*
     * Default timezone for store Redis TTL
     */
    'timezone' => 'UTC',
];
