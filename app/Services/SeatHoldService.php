<?php

namespace App\Services;

use App\Enums\SeatStatus;
use App\Exceptions\InvalidSeatSelectionException;
use App\Exceptions\SeatsUnavailableException;
use App\Models\Seat;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SeatHoldService
{
    public function __construct(private readonly SeatLockService $locks)
    {
    }

    /**
     * Locks the seats in Redis (all or none), flips them to held in one conditional UPDATE and runs
     * $reserve inside the same transaction. Redis keys are released only when the transaction did not commit.
     *
     * @template T
     *
     * @param  list<int>  $seatIds
     * @param  Closure(Collection<int, Seat>): T  $reserve
     * @return T
     */
    public function hold(int $userId, array $seatIds, Closure $reserve): mixed
    {
        $ids = $this->normalize($seatIds);
        $seats = $this->loadSeats($ids);
        $token = 'user:' . $userId;

        if (! $this->locks->lock($ids, $token)) {
            throw new SeatsUnavailableException();
        }

        $committed = false;

        try {
            $result = DB::transaction(function () use ($ids, $seats, $reserve) {
                $held = DB::table('seats')
                    ->whereIn('id', $ids)
                    ->where('status', SeatStatus::Available->value)
                    ->update(['status' => SeatStatus::Held->value]);

                if ($held !== count($ids)) {
                    throw new SeatsUnavailableException();
                }

                return $reserve($seats);
            });

            $committed = true;

            return $result;
        } finally {
            if (! $committed) {
                $this->locks->release($ids, $token);
            }
        }
    }

    /**
     * @param  list<int>  $seatIds
     * @return list<int>
     */
    private function normalize(array $seatIds): array
    {
        $ids = array_values(array_unique($seatIds));
        sort($ids);

        if ($ids === []) {
            throw new InvalidSeatSelectionException('Select at least one seat.');
        }

        $max = (int) config('ticketing.max_seats_per_order');

        if (count($ids) > $max) {
            throw new InvalidSeatSelectionException("You can hold at most {$max} seats per order.");
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Seat>
     */
    private function loadSeats(array $ids): Collection
    {
        $seats = Seat::query()->with('ticketTier')->whereIn('id', $ids)->get();

        if ($seats->count() !== count($ids)) {
            throw new InvalidSeatSelectionException('One or more seats do not exist.');
        }

        $events = $seats->map(fn (Seat $seat): int => (int) $seat->ticketTier->event_id)->unique();

        if ($events->count() !== 1) {
            throw new InvalidSeatSelectionException('All seats must belong to the same event.');
        }

        return $seats;
    }
}
