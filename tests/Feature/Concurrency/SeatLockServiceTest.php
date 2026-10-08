<?php

use App\Services\SeatLockService;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    // Feature tests flush only the cache connection; seat_hold keys live on the default one (DB 10 in tests).
    Redis::flushdb();
});

it('locks every seat with the holder token and the ttl', function () {
    $locked = app(SeatLockService::class)->lock([1, 2, 3], 'token-a', 600);

    expect($locked)->toBeTrue();

    foreach ([1, 2, 3] as $id) {
        expect(Redis::get("seat_hold:{$id}"))->toBe('token-a')
            ->and(Redis::ttl("seat_hold:{$id}"))->toBeGreaterThan(590)->toBeLessThanOrEqual(600);
    }
});

it('uses the configured hold ttl when none is given', function () {
    config(['ticketing.hold_ttl' => 120]);

    app(SeatLockService::class)->lock([10], 'token-a');

    expect(Redis::ttl('seat_hold:10'))->toBeGreaterThan(110)->toBeLessThanOrEqual(120);
});

it('locks all seats or none', function () {
    $service = app(SeatLockService::class);
    $service->lock([1, 2], 'token-a', 600);

    $second = $service->lock([2, 3], 'token-b', 600);

    expect($second)->toBeFalse()
        ->and(Redis::exists('seat_hold:3'))->toBe(0)
        ->and(Redis::get('seat_hold:2'))->toBe('token-a');
});

it('does not release seats held under another token', function () {
    $service = app(SeatLockService::class);
    $service->lock([1, 2], 'token-a', 600);

    expect($service->release([1, 2], 'token-b'))->toBe(0)
        ->and(Redis::exists('seat_hold:1'))->toBe(1)
        ->and(Redis::exists('seat_hold:2'))->toBe(1)
        ->and($service->release([1, 2], 'token-a'))->toBe(2)
        ->and(Redis::exists('seat_hold:1'))->toBe(0)
        ->and(Redis::exists('seat_hold:2'))->toBe(0);
});

it('releases only its own seats from a mixed list', function () {
    $service = app(SeatLockService::class);
    $service->lock([1], 'token-a', 600);
    $service->lock([2], 'token-b', 600);

    expect($service->release([1, 2], 'token-a'))->toBe(1)
        ->and(Redis::exists('seat_hold:1'))->toBe(0)
        ->and(Redis::get('seat_hold:2'))->toBe('token-b');
});

it('lets another holder lock the seats after a release', function () {
    $service = app(SeatLockService::class);
    $service->lock([1, 2], 'token-a', 600);
    $service->release([1, 2], 'token-a');

    expect($service->lock([1, 2], 'token-b', 600))->toBeTrue()
        ->and(Redis::get('seat_hold:1'))->toBe('token-b');
});

it('rejects an empty seat list', function () {
    $service = app(SeatLockService::class);

    expect(fn () => $service->lock([], 'token-a'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->release([], 'token-a'))->toThrow(InvalidArgumentException::class);
});
