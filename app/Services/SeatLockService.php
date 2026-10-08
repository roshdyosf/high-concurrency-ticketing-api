<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

class SeatLockService
{
    private const KEY_PREFIX = 'seat_hold:';

    /**
     * KEYS = seat_hold:{id} ... ; ARGV[1] = holder token ; ARGV[2] = TTL in seconds.
     * Returns 1 when every seat was locked, 0 when at least one was already held.
     */
    private const LOCK_SCRIPT = <<<'LUA'
        for i = 1, #KEYS do
            if redis.call('EXISTS', KEYS[i]) == 1 then
                return 0
            end
        end
        for i = 1, #KEYS do
            redis.call('SET', KEYS[i], ARGV[1], 'EX', ARGV[2])
        end
        return 1
        LUA;

    /**
     * KEYS = seat_hold:{id} ... ; ARGV[1] = holder token.
     * Deletes each key only if it still holds this token; returns how many were deleted.
     */
    private const RELEASE_SCRIPT = <<<'LUA'
        local released = 0
        for i = 1, #KEYS do
            if redis.call('GET', KEYS[i]) == ARGV[1] then
                redis.call('DEL', KEYS[i])
                released = released + 1
            end
        end
        return released
        LUA;
    /**
     * @param  list<int>  $seatIds
     */
    public function lock(array $seatIds, string $token, ?int $ttl = null): bool
    {
        $keys = $this->keysFor($seatIds);
        $ttl ??= (int) config('ticketing.hold_ttl');

        return (int) Redis::connection()->command('eval', [
            self::LOCK_SCRIPT,
            [...$keys, $token, $ttl],
            count($keys),
        ]) === 1;
    }

    /**
     * @param  list<int>  $seatIds
     */
    public function release(array $seatIds, string $token): int
    {
        $keys = $this->keysFor($seatIds);

        return (int) Redis::connection()->command('eval', [
            self::RELEASE_SCRIPT,
            [...$keys, $token],
            count($keys),
        ]);
    }

    /**
     * @param  list<int>  $seatIds
     * @return list<string>
     */
    private function keysFor(array $seatIds): array
    {
        if ($seatIds === []) {
            throw new InvalidArgumentException('At least one seat id is required.');
        }

        return array_map(
            fn (int $id): string => self::KEY_PREFIX . $id,
            $seatIds,
        );
    }
}
