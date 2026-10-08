<?php

namespace App\Services;

use App\Enums\SeatStatus;
use App\Exceptions\InvalidSeatSelectionException;
use App\Models\Seat;

class ContiguousSeatFinder
{
    /**
     * Suggests groups of $count adjacent available seats of a tier. Nothing is locked here.
     *
     * @return list<list<Seat>>
     */
    public function find(int $tierId, int $count): array
    {
        $max = (int) config('ticketing.max_seats_per_order');

        if ($count < 1 || $count > $max) {
            throw new InvalidSeatSelectionException("Count must be between 1 and {$max}.");
        }

        $limit = (int) config('ticketing.contiguous_max_groups');

        $seats = Seat::query()
            ->where('ticket_tier_id', $tierId)
            ->where('status', SeatStatus::Available->value)
            ->orderByRaw('char_length(row_label)')
            ->orderBy('row_label')
            ->orderBy('seat_number')
            ->get(['id', 'row_label', 'seat_number']);

        $groups = [];
        $run = [];
        $previous = null;

        foreach ($seats as $seat) {
            $continues = $previous !== null
                && $seat->row_label === $previous->row_label
                && $seat->seat_number === $previous->seat_number + 1;

            if ($continues) {
                $run[] = $seat;
            } else {
                $run = [$seat];
            }

            $previous = $seat;

            if (count($run) === $count) {
                $groups[] = $run;

                if (count($groups) === $limit) {
                    break;
                }
            }
        }

        return $groups;
    }
}
