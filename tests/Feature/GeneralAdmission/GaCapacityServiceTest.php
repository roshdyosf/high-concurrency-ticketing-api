<?php

use App\Models\TicketTier;
use App\Services\GaCapacityService;
use Illuminate\Support\Facades\Redis;

function gaTier(int $capacity = 5): TicketTier
{
    return TicketTier::factory()->create([
        'type' => 'general_admission',
        'total_capacity' => $capacity,
    ]);
}

it('initializes the counter from total_capacity', function () {
    $tier = gaTier(5);
    $service = app(GaCapacityService::class);

    expect($service->initialize($tier))->toBeTrue()
        ->and((int) Redis::get($service->key($tier->id)))->toBe(5);
});

it('does not overwrite an existing counter on re-initialize', function () {
    $tier = gaTier(5);
    $service = app(GaCapacityService::class);
    Redis::set($service->key($tier->id), 2);

    expect($service->initialize($tier))->toBeFalse()
        ->and((int) Redis::get($service->key($tier->id)))->toBe(2);
});

it('decrements the counter when enough capacity remains', function () {
    $tier = gaTier(5);
    $service = app(GaCapacityService::class);
    $service->initialize($tier);

    expect($service->decrement($tier->id, 3))->toBeTrue()
        ->and((int) Redis::get($service->key($tier->id)))->toBe(2);
});

it('refuses when the quantity exceeds the remaining capacity and leaves it unchanged', function () {
    $tier = gaTier(2);
    $service = app(GaCapacityService::class);
    $service->initialize($tier);

    expect($service->decrement($tier->id, 3))->toBeFalse()
        ->and((int) Redis::get($service->key($tier->id)))->toBe(2);
});

it('allows draining to exactly zero and then refuses', function () {
    $tier = gaTier(2);
    $service = app(GaCapacityService::class);
    $service->initialize($tier);

    expect($service->decrement($tier->id, 2))->toBeTrue()
        ->and((int) Redis::get($service->key($tier->id)))->toBe(0)
        ->and($service->decrement($tier->id, 1))->toBeFalse()
        ->and((int) Redis::get($service->key($tier->id)))->toBe(0);
});

it('refuses when the counter key does not exist and does not create it', function () {
    $service = app(GaCapacityService::class);

    expect($service->decrement(999, 1))->toBeFalse()
        ->and(Redis::exists($service->key(999)))->toBe(0);
});

it('rejects a quantity below 1', function () {
    app(GaCapacityService::class)->decrement(1, 0);
})->throws(InvalidArgumentException::class);

it('restores capacity onto an existing counter', function () {
    $tier = gaTier(5);
    $service = app(GaCapacityService::class);
    $service->initialize($tier);
    $service->decrement($tier->id, 3);

    expect($service->restore($tier->id, 2))->toBeTrue()
        ->and((int) Redis::get($service->key($tier->id)))->toBe(4);
});

it('does not create a counter when restoring a missing key', function () {
    $service = app(GaCapacityService::class);

    expect($service->restore(999, 2))->toBeFalse()
        ->and(Redis::exists($service->key(999)))->toBe(0);
});

it('makes drained capacity bookable again after a restore', function () {
    $tier = gaTier(2);
    $service = app(GaCapacityService::class);
    $service->initialize($tier);
    $service->decrement($tier->id, 2);

    expect($service->decrement($tier->id, 1))->toBeFalse();

    $service->restore($tier->id, 1);

    expect($service->decrement($tier->id, 1))->toBeTrue()
        ->and((int) Redis::get($service->key($tier->id)))->toBe(0);
});

it('rejects restoring a quantity below 1', function () {
    app(GaCapacityService::class)->restore(1, 0);
})->throws(InvalidArgumentException::class);
