<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = [
        'title',
        'description',
        'venue_name',
        'location',
        'event_date',
        'end_date',
        'image_url',
        'image_public_id',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'end_date' => 'datetime',
            'status' => EventStatus::class,
        ];
    }
    /**
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }
    /**
     * @return HasMany<TicketTier, $this>
     */
    public function ticketTiers(): HasMany
    {
        return $this->hasMany(TicketTier::class);
    }
    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
    /**
     * @return HasMany<DiscountCode, $this>
     */
    public function discountCodes(): HasMany
    {
        return $this->hasMany(DiscountCode::class);
    }
    /**
     * @return HasMany<EventGatekeeper, $this>
     */
    public function gatekeeperAssignments(): HasMany
    {
        return $this->hasMany(EventGatekeeper::class);
    }
}
