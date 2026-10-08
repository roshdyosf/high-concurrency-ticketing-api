<?php

use App\Exceptions\InvalidSeatSelectionException;
use App\Exceptions\SeatsUnavailableException;
use App\Enums\SeatStatus;
use App\Models\Seat;
use App\Models\TicketTier;
use App\Models\User;
use App\Services\SeatHoldService;
use App\Services\SeatLockService;
use Illuminate\Support\Facades\Redis;

/**
 * @property SeatHoldService $service
 * @property User $user
 * @property list<int> $seatIds
 */

beforeEach(function () {
    $this->service = app(SeatHoldService::class);
    $this->user = User::factory()->create();
    $this->seatIds = Seat::query()
        ->where('ticket_tier_id', TicketTier::factory()->withSeats(rows: 1, perRow: 3)->create()->id)
        ->orderBy('id')
        ->pluck('id')
        ->all();
});

it('holds the seats in the database and in redis and returns the closure result', function () {
    $result = $this->service->hold($this->user->id, $this->seatIds, fn ($seats) => $seats->count());

    expect($result)->toBe(3)
        ->and(Seat::whereIn('id', $this->seatIds)->where('status', 'held')->count())->toBe(3);

    foreach ($this->seatIds as $id) {
        expect(Redis::get("seat_hold:{$id}"))->toBe('user:' . $this->user->id)
            ->and(Redis::ttl("seat_hold:{$id}"))->toBeGreaterThan(590);
    }
});

it('rolls back every seat and frees the redis keys when one seat is already booked', function () {
    Seat::find($this->seatIds[1])->forceFill(['status' => SeatStatus::Booked])->save();

    expect(fn () => $this->service->hold($this->user->id, $this->seatIds, fn ($seats) => true))
        ->toThrow(SeatsUnavailableException::class);

    expect(Seat::whereIn('id', $this->seatIds)->where('status', 'available')->count())->toBe(2);

    foreach ($this->seatIds as $id) {
        expect(Redis::exists("seat_hold:{$id}"))->toBe(0);
    }
});

it('refuses when another holder owns a redis lock and leaves that lock untouched', function () {
    app(SeatLockService::class)->lock([$this->seatIds[1]], 'user:999', 600);

    expect(fn () => $this->service->hold($this->user->id, $this->seatIds, fn ($seats) => true))
        ->toThrow(SeatsUnavailableException::class);

    expect(Redis::get("seat_hold:{$this->seatIds[1]}"))->toBe('user:999')
        ->and(Redis::exists("seat_hold:{$this->seatIds[0]}"))->toBe(0)
        ->and(Seat::whereIn('id', $this->seatIds)->where('status', 'available')->count())->toBe(3);
});

it('rolls back and frees the keys when the reserve closure throws', function () {
    expect(fn () => $this->service->hold($this->user->id, $this->seatIds, function () {
        throw new RuntimeException('order creation failed');
    }))->toThrow(RuntimeException::class);

    expect(Seat::whereIn('id', $this->seatIds)->where('status', 'available')->count())->toBe(3);

    foreach ($this->seatIds as $id) {
        expect(Redis::exists("seat_hold:{$id}"))->toBe(0);
    }
});

it('fails an overlapping second hold as a whole and keeps the first one intact', function () {
    $other = User::factory()->create();
    $this->service->hold($this->user->id, [$this->seatIds[0], $this->seatIds[1]], fn ($seats) => true);

    expect(fn () => $this->service->hold($other->id, [$this->seatIds[1], $this->seatIds[2]], fn ($seats) => true))
        ->toThrow(SeatsUnavailableException::class);

    expect(Redis::get("seat_hold:{$this->seatIds[1]}"))->toBe('user:' . $this->user->id)
        ->and(Redis::exists("seat_hold:{$this->seatIds[2]}"))->toBe(0)
        ->and(Seat::find($this->seatIds[2])->status)->toBe(SeatStatus::Available);
});

it('rejects more seats than the configured maximum without side effects', function () {
    config(['ticketing.max_seats_per_order' => 2]);

    expect(fn () => $this->service->hold($this->user->id, $this->seatIds, fn ($seats) => true))
        ->toThrow(InvalidSeatSelectionException::class);

    expect(Redis::exists("seat_hold:{$this->seatIds[0]}"))->toBe(0)
        ->and(Seat::whereIn('id', $this->seatIds)->where('status', 'available')->count())->toBe(3);
});

it('rejects an unknown seat id', function () {
    expect(fn () => $this->service->hold($this->user->id, [$this->seatIds[0], 999999], fn ($seats) => true))
        ->toThrow(InvalidSeatSelectionException::class);

    expect(Redis::exists("seat_hold:{$this->seatIds[0]}"))->toBe(0);
});

it('rejects seats that belong to different events', function () {
    $foreignSeat = Seat::query()
        ->where('ticket_tier_id', TicketTier::factory()->withSeats(rows: 1, perRow: 1)->create()->id)
        ->value('id');

    expect(fn () => $this->service->hold($this->user->id, [$this->seatIds[0], $foreignSeat], fn ($seats) => true))
        ->toThrow(InvalidSeatSelectionException::class);

    expect(Redis::exists("seat_hold:{$this->seatIds[0]}"))->toBe(0);
});

it('rejects an empty seat list', function () {
    expect(fn () => $this->service->hold($this->user->id, [], fn ($seats) => true))
        ->toThrow(InvalidSeatSelectionException::class);
});

it('removes duplicate seat ids before holding', function () {
    $count = $this->service->hold(
        $this->user->id,
        [$this->seatIds[0], $this->seatIds[0], $this->seatIds[1]],
        fn ($seats) => $seats->count(),
    );

    expect($count)->toBe(2)
        ->and(Seat::whereIn('id', $this->seatIds)->where('status', 'held')->count())->toBe(2);
});

it('renders the hold errors as json with the agreed status and code', function () {
    $conflict = (new SeatsUnavailableException())->render();
    $invalid = (new InvalidSeatSelectionException('bad selection'))->render();

    expect($conflict->getStatusCode())->toBe(409)
        ->and($conflict->getData(true)['code'])->toBe('SEATS_UNAVAILABLE')
        ->and($invalid->getStatusCode())->toBe(422)
        ->and($invalid->getData(true))->toBe(['code' => 'INVALID_SEAT_SELECTION', 'message' => 'bad selection']);
});
