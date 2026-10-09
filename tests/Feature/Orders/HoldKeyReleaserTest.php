<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seat;
use App\Models\TicketTier;
use App\Services\HoldKeyReleaser;
use App\Services\SeatLockService;
use Illuminate\Support\Facades\Redis;

/**
 * @return array{0: Order, 1: list<int>}
 */
function hkrOrderWithSeats(int $perRow = 3): array
{
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: $perRow)->create();
    $seatIds = Seat::where('ticket_tier_id', $tier->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    $order = Order::factory()->create();

    foreach ($seatIds as $seatId) {
        (new OrderItem())->forceFill([
            'order_id' => $order->id,
            'ticket_tier_id' => $tier->id,
            'seat_id' => $seatId,
            'quantity' => 1,
            'unit_price' => 50,
        ])->save();
    }

    return [$order, $seatIds];
}

it('builds the holder token from the customer id', function () {
    expect(HoldKeyReleaser::tokenFor(7))->toBe('user:7');
});

it('releases the keys of every seat in the order', function () {
    [$order, $seatIds] = hkrOrderWithSeats();
    app(SeatLockService::class)->lock($seatIds, HoldKeyReleaser::tokenFor($order->customer_id), 600);

    expect(app(HoldKeyReleaser::class)->release($order))->toBe(3);

    foreach ($seatIds as $id) {
        expect(Redis::exists("seat_hold:{$id}"))->toBe(0);
    }
});

it('leaves keys held by another holder untouched', function () {
    [$order, $seatIds] = hkrOrderWithSeats();
    $locks = app(SeatLockService::class);
    $locks->lock([$seatIds[0], $seatIds[2]], HoldKeyReleaser::tokenFor($order->customer_id), 600);
    $locks->lock([$seatIds[1]], 'user:999999', 600);

    expect(app(HoldKeyReleaser::class)->release($order))->toBe(2)
        ->and(Redis::get("seat_hold:{$seatIds[1]}"))->toBe('user:999999');
});

it('is idempotent when called twice', function () {
    [$order, $seatIds] = hkrOrderWithSeats();
    app(SeatLockService::class)->lock($seatIds, HoldKeyReleaser::tokenFor($order->customer_id), 600);
    $releaser = app(HoldKeyReleaser::class);

    expect($releaser->release($order))->toBe(3)
        ->and($releaser->release($order))->toBe(0);
});

it('returns zero for a general admission order without seat items', function () {
    $tier = TicketTier::factory()->create(['type' => 'general_admission', 'total_capacity' => 5]);
    $order = Order::factory()->create();
    (new OrderItem())->forceFill([
        'order_id' => $order->id,
        'ticket_tier_id' => $tier->id,
        'seat_id' => null,
        'quantity' => 2,
        'unit_price' => 50,
    ])->save();

    expect(app(HoldKeyReleaser::class)->release($order))->toBe(0);
});

it('returns zero for an order with no items', function () {
    expect(app(HoldKeyReleaser::class)->release(Order::factory()->create()))->toBe(0);
});
