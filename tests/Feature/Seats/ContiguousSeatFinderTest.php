<?php

use App\Enums\SeatStatus;
use App\Exceptions\InvalidSeatSelectionException;
use App\Models\Seat;
use App\Models\TicketTier;
use App\Services\ContiguousSeatFinder;

function cfAddSeats(TicketTier $tier, string $row, array $numbers, SeatStatus $status = SeatStatus::Available): void
{
    foreach ($numbers as $number) {
        Seat::factory()->withStatus($status)->create([
            'ticket_tier_id' => $tier->id,
            'row_label' => $row,
            'seat_number' => $number,
        ]);
    }
}

function cfLabels(array $groups): array
{
    return array_map(
        fn (array $group) => array_map(fn ($seat) => $seat->row_label . $seat->seat_number, $group),
        $groups,
    );
}

beforeEach(function () {
    $this->tier = TicketTier::factory()->create();
    $this->finder = app(ContiguousSeatFinder::class);
});

it('suggests the first N seats of a run of adjacent seats', function () {
    cfAddSeats($this->tier, 'A', [1, 2, 3, 4, 5]);

    expect(cfLabels($this->finder->find($this->tier->id, 3)))->toBe([['A1', 'A2', 'A3']]);
});

it('does not bridge a gap in seat numbers', function () {
    cfAddSeats($this->tier, 'A', [1, 2, 4, 5]);

    expect($this->finder->find($this->tier->id, 3))->toBe([]);
});

it('treats held and booked seats as breaks in a run', function () {
    cfAddSeats($this->tier, 'A', [1, 2]);
    cfAddSeats($this->tier, 'A', [3], SeatStatus::Booked);
    cfAddSeats($this->tier, 'A', [4], SeatStatus::Held);
    cfAddSeats($this->tier, 'A', [5, 6]);

    expect(cfLabels($this->finder->find($this->tier->id, 2)))->toBe([['A1', 'A2'], ['A5', 'A6']]);
});

it('never joins seats of different rows', function () {
    cfAddSeats($this->tier, 'A', [1, 2]);
    cfAddSeats($this->tier, 'B', [3, 4]);

    expect($this->finder->find($this->tier->id, 3))->toBe([]);
});

it('returns one group per run even when the run is longer than requested', function () {
    cfAddSeats($this->tier, 'A', [1, 2, 3, 4, 5, 6]);
    cfAddSeats($this->tier, 'B', [1, 2, 3]);

    expect(cfLabels($this->finder->find($this->tier->id, 2)))->toBe([['A1', 'A2'], ['B1', 'B2']]);
});

it('returns at most three groups by default', function () {
    foreach (['A', 'B', 'C', 'D', 'E'] as $row) {
        cfAddSeats($this->tier, $row, [1, 2]);
    }

    expect(cfLabels($this->finder->find($this->tier->id, 2)))
        ->toBe([['A1', 'A2'], ['B1', 'B2'], ['C1', 'C2']]);
});

it('honours the configured group limit', function () {
    config(['ticketing.contiguous_max_groups' => 1]);

    foreach (['A', 'B'] as $row) {
        cfAddSeats($this->tier, $row, [1, 2]);
    }

    expect(cfLabels($this->finder->find($this->tier->id, 2)))->toBe([['A1', 'A2']]);
});

it('orders rows by label length before the label itself', function () {
    cfAddSeats($this->tier, 'AA', [1, 2]);
    cfAddSeats($this->tier, 'Z', [1, 2]);
    cfAddSeats($this->tier, 'B', [1, 2]);

    expect(cfLabels($this->finder->find($this->tier->id, 2)))
        ->toBe([['B1', 'B2'], ['Z1', 'Z2'], ['AA1', 'AA2']]);
});

it('rejects a count outside 1 to the configured maximum', function () {
    expect(fn () => $this->finder->find($this->tier->id, 0))->toThrow(InvalidSeatSelectionException::class)
        ->and(fn () => $this->finder->find($this->tier->id, 7))->toThrow(InvalidSeatSelectionException::class)
        ->and($this->finder->find($this->tier->id, 6))->toBe([]);
});

it('returns the first seat of each run when the count is one', function () {
    cfAddSeats($this->tier, 'A', [1, 2, 4]);

    expect(cfLabels($this->finder->find($this->tier->id, 1)))->toBe([['A1'], ['A4']]);
});

it('ignores seats of other tiers', function () {
    cfAddSeats($this->tier, 'A', [1, 2]);
    cfAddSeats(TicketTier::factory()->create(), 'A', [3, 4, 5]);

    expect($this->finder->find($this->tier->id, 3))->toBe([]);
});
