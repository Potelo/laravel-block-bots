<?php

namespace Potelo\LaravelBlockBots\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

class GuestPageQuota
{
    // All keys are in the network's Redis Cluster slot. Only admitted requests
    // consume both budgets. Repeated refusals cannot exhaust neighbouring IPs.
    private const ADMIT = <<<'LUA'
local counts = redis.call('MGET', KEYS[1], KEYS[2])
if #KEYS == 3 and redis.call('EXISTS', KEYS[3]) == 1 then
    return {0, 0, 0}
end
for i = 1, 2 do
    local count = tonumber(counts[i]) or 0
    local limit = tonumber(ARGV[i])
    if count >= limit then
        local first = 0
        if count == limit then
            redis.call('INCR', KEYS[i])
            if not counts[i] then redis.call('EXPIREAT', KEYS[i], ARGV[3]) end
            first = 1
        end
        return {i, first, redis.call('TTL', KEYS[i])}
    end
end
for i = 1, 2 do
    if redis.call('INCR', KEYS[i]) == 1 then
        redis.call('EXPIREAT', KEYS[i], ARGV[3])
    end
end
if #KEYS == 3 then
    local ttl = math.min(300, redis.call('TTL', KEYS[1]))
    if ttl > 0 then redis.call('SET', KEYS[3], 1, 'EX', ttl) end
end
return {0, 0, 0}
LUA;

    /**
     * Returns null on admission, or the limiting counter, first refusal and TTL.
     */
    public static function admit($ip, array $limits, $page, $timezone)
    {
        $bytes = @inet_pton((string) $ip);
        if ($bytes !== false && strlen($bytes) === 16 && substr($bytes, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $bytes = substr($bytes, 12);
        }
        // A missing address gets a bounded, separate quota, never a trusted identity.
        $identity = $bytes === false ? 'unknown' : bin2hex(substr($bytes, 0, 8));
        $network = $bytes === false ? 'unknown' : bin2hex(substr($bytes, 0, strlen($bytes) === 4 ? 3 : 6));
        $prefix = 'block_bot:guest:v1:{' . $network . '}:';
        $keys = [$prefix . 'client:' . $identity, $prefix . 'network'];
        if ($page !== null && $page !== '') {
            $keys[] = $prefix . 'page:' . $identity . ':' . sha1($page);
        }

        $reset = Carbon::tomorrow($timezone)->startOfDay()->getTimestamp();
        $args = array_merge($keys, [(int) $limits[0], (int) $limits[1], $reset]);
        list($scope, $first, $ttl) = Redis::eval(self::ADMIT, count($keys), ...$args);
        if ((int) $scope === 0) {
            return null;
        }
        $index = $scope - 1;
        return ['key' => $keys[$index], 'limit' => (int) $limits[$index], 'first' => (bool) $first, 'ttl' => max(1, (int) $ttl)];
    }
}
