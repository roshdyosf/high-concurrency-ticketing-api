<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Seat;
use App\Models\TicketTier;
use App\Services\HoldKeyReleaser;
use Illuminate\Support\Facades\Redis;

function rbItem(Order $order, TicketTier $tier, int $quantity, ?int $seatId = null): void
{
    (new OrderItem())->forceFill([
        'order_id' => $order->id,
        'ticket_tier_id' => $tier->id,
        'seat_id' => $seatId,
        'quantity' => $quantity,
        'unit_price' => 50,
    ])->save();
}

it('rebuilds the ga counter from paid orders and active holds only', function () {
    $tier = TicketTier::factory()->create(['type' => 'general_admission', 'total_capacity' => 10]);

    rbItem(Order::factory()->paid()->create(), $tier, 2);
    rbItem(Order::factory()->create(), $tier, 3);
    rbItem(Order::factory()->expiredHold()->create(), $tier, 4);
    rbItem(Order::factory()->create(['status' => 'refunded']), $tier, 1);

    $this->artisan('inventory:rebuild-ga')->assertSuccessful();

    expect((int) Redis::get("tier_capacity:{$tier->id}"))->toBe(5);
});

it('sets the counter to the full capacity when nothing was sold', function () {
    $tier = TicketTier::factory()->create(['type' => 'general_admission', 'total_capacity' => 7]);

    $this->artisan('inventory:rebuild-ga')->assertSuccessful();

    expect((int) Redis::get("tier_capacity:{$tier->id}"))->toBe(7);
});

it('overwrites a stale counter with the value derived from the database', function () {
    $tier = TicketTier::factory()->create(['type' => 'general_admission', 'total_capacity' => 10]);
    Redis::set("tier_capacity:{$tier->id}", 99);
    rbItem(Order::factory()->paid()->create(), $tier, 4);

    $this->artisan('inventory:rebuild-ga')->assertSuccessful();

    expect((int) Redis::get("tier_capacity:{$tier->id}"))->toBe(6);
});

it('does not create counters for seated tiers', function () {
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: 2)->create();

    $this->artisan('inventory:rebuild-ga')->assertSuccessful();

    expect(Redis::exists("tier_capacity:{$tier->id}"))->toBe(0);
});

it('recreates seat locks for an active hold with the holder token and the remaining ttl', function () {
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: 2)->create();
    $seatIds = Seat::where('ticket_tier_id', $tier->id)->orderBy('id')->pluck('id')->all();
    $order = Order::factory()->create(['expires_at' => now()->addMinutes(5)]);

    foreach ($seatIds as $seatId) {
        rbItem($order, $tier, 1, $seatId);
    }

    $this->artisan('inventory:rebuild-seat-locks')->assertSuccessful();

    foreach ($seatIds as $seatId) {
        expect(Redis::get("seat_hold:{$seatId}"))->toBe(HoldKeyReleaser::tokenFor($order->customer_id))
            ->and(Redis::ttl("seat_hold:{$seatId}"))->toBeGreaterThan(290)->toBeLessThanOrEqual(300);
    }
});

it('skips seats of expired and paid orders', function () {
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: 2)->create();
    [$expiredSeat, $paidSeat] = Seat::where('ticket_tier_id', $tier->id)->orderBy('id')->pluck('id')->all();

    rbItem(Order::factory()->expiredHold()->create(), $tier, 1, $expiredSeat);
    rbItem(Order::factory()->paid()->create(), $tier, 1, $paidSeat);

    $this->artisan('inventory:rebuild-seat-locks')->assertSuccessful();

    expect(Redis::exists("seat_hold:{$expiredSeat}"))->toBe(0)
        ->and(Redis::exists("seat_hold:{$paidSeat}"))->toBe(0);
});

it('leaves existing seat locks untouched when run on a live redis', function () {
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: 1)->create();
    $seatId = Seat::where('ticket_tier_id', $tier->id)->value('id');
    $order = Order::factory()->create();
    rbItem($order, $tier, 1, $seatId);
    Redis::set("seat_hold:{$seatId}", 'user:999999', 'EX', 100);

    $this->artisan('inventory:rebuild-seat-locks')->assertSuccessful();

    expect(Redis::get("seat_hold:{$seatId}"))->toBe('user:999999')
        ->and(Redis::ttl("seat_hold:{$seatId}"))->toBeLessThanOrEqual(100);
});
