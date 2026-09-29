<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\UserRole;

class User extends Authenticatable
{
    use HasFactory;
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function scannedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'scanned_by');
    }
    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'customer_id');
    }

    public function reviewedRefundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'reviewed_by');
    }
}
