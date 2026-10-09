<?php

namespace App\Services;

use App\Models\Order;

class HoldKeyReleaser
{
    public function __construct(private readonly SeatLockService $locks)
    {
    }

    public static function tokenFor(int $customerId): string
    {
        return "user:{$customerId}";
    }

    public function release(Order $order): int
    {
        /** @var list<int> $seatIds */
        $seatIds = $order->items()
            ->whereNotNull('seat_id')
            ->pluck('seat_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($seatIds === []) {
            return 0;
        }

        return $this->locks->release($seatIds, self::tokenFor((int) $order->customer_id));
    }
}
