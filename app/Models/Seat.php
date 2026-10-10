<?php

namespace App\Models;

use App\Enums\SeatStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\SeatFactory;

/**
 * @property SeatStatus $status
 */

class Seat extends Model
{
    /** @use HasFactory<SeatFactory> */
    use HasFactory;

    protected $fillable = [
        'row_label',
        'seat_number',
    ];

    protected function casts(): array
    {
        return [
            'status' => SeatStatus::class,
        ];
    }
    /**
     * @return BelongsTo<TicketTier, $this>
     */
    public function ticketTier(): BelongsTo
    {
        return $this->belongsTo(TicketTier::class);
    }
    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
