<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'event_id' => Event::factory(),
            'currency' => 'USD',
            'subtotal' => 100,
            'discount_amount' => 0,
            'total_amount' => 100,
            'status' => OrderStatus::Pending,
            'expires_at' => Order::holdExpiresAt(),
        ];
    }

    public function expiredHold(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Paid, 'paid_at' => now()]);
    }
}
