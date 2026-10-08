<?php

namespace Database\Factories;

use App\Enums\TierType;
use App\Models\Event;
use App\Models\Seat;
use App\Models\TicketTier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<TicketTier>
 */
class TicketTierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => fake()->randomElement(['VIP', 'Standard', 'Balcony', 'Floor']),
            'type' => TierType::Seated,
            'price' => fake()->randomFloat(2, 10, 200),
            'total_capacity' => 0,
        ];
    }

    public function generalAdmission(int $capacity = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TierType::GeneralAdmission,
            'total_capacity' => $capacity,
        ]);
    }

    /**
     * Creates $rows x $perRow seats (rows A, B, ...; at most 26 rows) and sets total_capacity to match.
     */
    public function withSeats(int $rows = 2, int $perRow = 5): static
    {
        return $this->afterCreating(function (TicketTier $tier) use ($rows, $perRow) {
            Seat::factory()
                ->count($rows * $perRow)
                ->sequence(fn (Sequence $sequence) => [
                    'row_label' => chr(65 + intdiv($sequence->index, $perRow)),
                    'seat_number' => ($sequence->index % $perRow) + 1,
                ])
                ->create(['ticket_tier_id' => $tier->id]);

            $tier->forceFill(['total_capacity' => $rows * $perRow])->save();
        });
    }
}
