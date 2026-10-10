<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\SeatStatus;
use App\Enums\TierType;
use App\Exceptions\EventNotEditableException;
use App\Exceptions\SeatsAlreadyGeneratedException;
use App\Exceptions\TierNotSeatedException;
use App\Models\Seat;
use App\Models\TicketTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrganizerSeatService
{
    private const INSERT_CHUNK = 1000;

    public function generate(TicketTier $tier, int $rows, int $seatsPerRow): TicketTier
    {
        $event = $tier->event;

        Gate::authorize('manage', $event);

        if (! in_array($event->status, [EventStatus::Draft, EventStatus::Published], true)) {
            throw new EventNotEditableException();
        }

        if ($tier->type !== TierType::Seated) {
            throw new TierNotSeatedException();
        }

        return DB::transaction(function () use ($tier, $rows, $seatsPerRow): TicketTier {
            $locked = TicketTier::query()->whereKey($tier->id)->lockForUpdate()->firstOrFail();

            if ($locked->seats()->exists()) {
                throw new SeatsAlreadyGeneratedException();
            }

            $now = now();
            $batch = [];

            for ($row = 0; $row < $rows; $row++) {
                $label = $this->rowLabel($row);

                for ($number = 1; $number <= $seatsPerRow; $number++) {
                    $batch[] = [
                        'ticket_tier_id' => $locked->id,
                        'row_label' => $label,
                        'seat_number' => $number,
                        'status' => SeatStatus::Available->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($batch) === self::INSERT_CHUNK) {
                        Seat::query()->insert($batch);
                        $batch = [];
                    }
                }
            }

            if ($batch !== []) {
                Seat::query()->insert($batch);
            }

            $locked->forceFill(['total_capacity' => $rows * $seatsPerRow])->save();

            return $locked;
        });
    }

    /**
     * 0 => A, 25 => Z, 26 => AA, 27 => AB ...
     */
    private function rowLabel(int $index): string
    {
        $label = '';
        $n = $index + 1;

        while ($n > 0) {
            $n--;
            $label = chr(65 + $n % 26) . $label;
            $n = intdiv($n, 26);
        }

        return $label;
    }
}
