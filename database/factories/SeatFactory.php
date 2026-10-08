<?php

namespace Database\Factories;

use App\Enums\SeatStatus;
use App\Models\Seat;
use App\Models\TicketTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seat>
 */
class SeatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_tier_id' => TicketTier::factory(),
            'row_label' => 'A',
            'seat_number' => fake()->unique()->numberBetween(1, 100000),
            'status' => SeatStatus::Available,
        ];
    }

    public function held(): static
    {
        return $this->withStatus(SeatStatus::Held);
    }

    public function booked(): static
    {
        return $this->withStatus(SeatStatus::Booked);
    }

    public function withStatus(SeatStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
