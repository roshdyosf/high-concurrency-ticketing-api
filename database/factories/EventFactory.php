<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(7, 60))->startOfHour();

        return [
            'organizer_id' => User::factory()->organizer(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'venue_name' => fake()->company(),
            'location' => fake()->city(),
            'event_date' => $start,
            'end_date' => $start->copy()->addHours(3),
            'status' => EventStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->withStatus(EventStatus::Published);
    }

    public function withStatus(EventStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
