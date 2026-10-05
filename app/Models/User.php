<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\UserRole;
use Database\Factories\UserFactory;

/**
 * @property UserRole $role
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasApiTokens;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_approved' => 'boolean',
            'is_banned' => 'boolean',
            'banned_at' => 'datetime',
            'organizer_reviewed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }
    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }
    /**
     * @return HasMany<Ticket, $this>
     */
    public function scannedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'scanned_by');
    }
    /**
     * @return HasMany<RefundRequest, $this>
     */
    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'customer_id');
    }
    /**
     * @return HasMany<RefundRequest, $this>
     */
    public function reviewedRefundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'reviewed_by');
    }
    /**
     * @return HasMany<EventGatekeeper, $this>
     */
    public function gatekeeperAssignments(): HasMany
    {
        return $this->hasMany(EventGatekeeper::class, 'user_id');
    }
}
