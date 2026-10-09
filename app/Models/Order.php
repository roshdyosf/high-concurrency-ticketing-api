<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public static function holdExpiresAt(): Carbon
    {
        return now()->addSeconds((int) config('ticketing.hold_ttl'));
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeActiveHolds(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Pending)->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeExpiredHolds(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Pending)->where('expires_at', '<=', now());
    }
    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
    /**
     * @return BelongsTo<DiscountCode, $this>
     */
    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }
    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
    /**
     * @return HasMany<RefundRequest, $this>
     */
    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }
}
