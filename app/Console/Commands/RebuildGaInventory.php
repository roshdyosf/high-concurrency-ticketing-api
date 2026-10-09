<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\TicketTier;
use App\Services\GaCapacityService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RebuildGaInventory extends Command
{
    protected $signature = 'inventory:rebuild-ga';

    protected $description = 'Rebuild general admission capacity counters in Redis from PostgreSQL';

    public function handle(GaCapacityService $capacity): int
    {
        $rebuilt = 0;

        TicketTier::query()
            ->where('type', 'general_admission')
            ->each(function (TicketTier $tier) use ($capacity, &$rebuilt): void {
                $taken = (int) OrderItem::query()
                    ->where('ticket_tier_id', $tier->id)
                    ->whereHas('order', fn (Builder $order) => $order->where(
                        fn (Builder $state) => $state
                            ->where('status', OrderStatus::Paid)
                            ->orWhere(fn (Builder $hold) => $hold->activeHolds())
                    ))
                    ->sum('quantity');

                $capacity->overwrite($tier->id, (int) $tier->total_capacity - $taken);
                $rebuilt++;
            });

        $this->info("Rebuilt {$rebuilt} general admission counter(s).");

        return self::SUCCESS;
    }
}
