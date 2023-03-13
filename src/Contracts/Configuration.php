<?php

namespace Potelo\LaravelBlockBots\Contracts;

/**
 * Class Configuration
 *
 * @property bool $enabled
 * @property string $mode
 * @property bool $use_default_allowed_bots
 * @property bool $block_when_crash
 * @property string $whitelist_key
 * @property string $fake_bot_list_key
 * @property string $pending_bot_list_key
 * @property string|null $ip_info_key
 * @property string[] $whitelist_ips
 * @property string[] $channels_info
 * @property string[] $channels_blocks
 * @property bool $log
 * @property bool $log_only_guest
 * @property string[] $allowed_bots
 * @property string[] $json_response
 * @property string[] $timezone
 */
class Configuration
{
    public function __get($name)
    {
        return config('block-bots.' . $name);
    }
}
