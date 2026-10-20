<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\GatekeeperInvitationStatus;

/**
 * @property GatekeeperInvitationStatus $status
 * @property Carbon $assigned_at
 * @property Carbon|null $revoked_at
 */

class EventGatekeeper extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => GatekeeperInvitationStatus::class,
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function gatekeeper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    /**
     * @return BelongsTo<User, $this>
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
