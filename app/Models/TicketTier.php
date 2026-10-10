<?php

namespace App\Models;

use App\Enums\TierType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\TicketTierFactory;

/**
 * @property TierType $type
 */

class TicketTier extends Model
{
    /** @use HasFactory<TicketTierFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'price',
        'total_capacity',
    ];

    protected function casts(): array
    {
        return [
            'type' => TierType::class,
            'price' => 'decimal:2',
        ];
    }
    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
    /**
     * @return HasMany<Seat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }
    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
