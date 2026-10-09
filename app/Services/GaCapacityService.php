<?php

namespace App\Services;

use App\Models\TicketTier;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Illuminate\Redis\Connections\PhpRedisConnection;

class GaCapacityService
{
    private const DECREMENT_SCRIPT = <<<'LUA'
local cur = tonumber(redis.call('GET', KEYS[1]) or '0')
local qty = tonumber(ARGV[1])
if cur < qty then return -1 end
return redis.call('DECRBY', KEYS[1], qty)
LUA;

    private const RESTORE_SCRIPT = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return -1 end
return redis.call('INCRBY', KEYS[1], tonumber(ARGV[1]))
LUA;

    public function key(int $tierId): string
    {
        return "tier_capacity:{$tierId}";
    }

    public function initialize(TicketTier $tier): bool
    {
        return (bool) Redis::setnx($this->key($tier->id), (int) $tier->total_capacity);
    }

    public function overwrite(int $tierId, int $available): void
    {
        Redis::set($this->key($tierId), max(0, $available));
    }

    public function decrement(int $tierId, int $quantity): bool
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $result = (int) $this->redis()->eval(self::DECREMENT_SCRIPT, 1, $this->key($tierId), $quantity);
        return $result >= 0;
    }

    public function restore(int $tierId, int $quantity): bool
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        return (int) $this->redis()->eval(self::RESTORE_SCRIPT, 1, $this->key($tierId), $quantity) >= 0;
    }

    private function redis(): PhpRedisConnection
    {
        /** @var PhpRedisConnection $redis */
        $redis = Redis::connection();

        return $redis;
    }
}
