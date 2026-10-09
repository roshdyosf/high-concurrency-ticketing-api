<?php

use App\Models\Order;

it('computes the hold expiry as now plus the configured ttl', function () {
    $this->freezeTime();
    config(['ticketing.hold_ttl' => 600]);

    expect(Order::holdExpiresAt()->equalTo(now()->addSeconds(600)))->toBeTrue();
});

it('creates a pending order with a future expiry by default', function () {
    $order = Order::factory()->create();

    expect($order->status->value)->toBe('pending')
        ->and($order->expires_at->isFuture())->toBeTrue();
});

it('returns only pending orders with a future expiry as active holds', function () {
    $active = Order::factory()->create();
    Order::factory()->expiredHold()->create();
    Order::factory()->paid()->create();

    expect(Order::activeHolds()->pluck('id')->all())->toBe([$active->id]);
});

it('returns only pending orders past their expiry as expired holds', function () {
    Order::factory()->create();
    $expired = Order::factory()->expiredHold()->create();
    Order::factory()->paid()->expiredHold()->create();

    expect(Order::expiredHolds()->pluck('id')->all())->toBe([$expired->id]);
});

it('treats an order expiring exactly now as expired, not active', function () {
    $this->freezeTime();
    $order = Order::factory()->create(['expires_at' => now()]);

    expect(Order::activeHolds()->count())->toBe(0)
        ->and(Order::expiredHolds()->pluck('id')->all())->toBe([$order->id]);
});
