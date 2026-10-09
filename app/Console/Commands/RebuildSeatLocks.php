<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\HoldKeyReleaser;
use App\Services\SeatLockService;
use Illuminate\Console\Command;

class RebuildSeatLocks extends Command
{
    protected $signature = 'inventory:rebuild-seat-locks';

    protected $description = 'Recreate seat hold keys in Redis for unexpired pending orders';

    public function handle(SeatLockService $locks): int
    {
        $rebuilt = 0;

        Order::query()
            ->activeHolds()
            ->with('items')
            ->each(function (Order $order) use ($locks, &$rebuilt): void {
                /** @var list<int> $seatIds */
                $seatIds = $order->items
                    ->pluck('seat_id')
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                if ($seatIds === []) {
                    return;
                }

                $ttl = max(1, (int) ceil(now()->diffInSeconds($order->expires_at, false)));

                if ($locks->lock($seatIds, HoldKeyReleaser::tokenFor((int) $order->customer_id), $ttl)) {
                    $rebuilt++;
                }
            });

        $this->info("Recreated seat locks for {$rebuilt} order(s).");

        return self::SUCCESS;
    }
}
