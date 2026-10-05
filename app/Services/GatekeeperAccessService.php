<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\GatekeeperInvitationStatus;
use App\Models\EventGatekeeper;

class GatekeeperAccessService
{
    /**
     * Is the user an accepted, non-revoked gatekeeper of a running event?
     * PostgreSQL only for now; the Redis cache layer is added in I7.
     */
    public function canScan(int $userId, int $eventId): bool
    {
        return EventGatekeeper::query()
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->where('status', GatekeeperInvitationStatus::Accepted->value)
            ->whereNull('revoked_at')
            ->whereHas('event', function ($query) {
                $query->where('status', '!=', EventStatus::Completed->value)
                    ->where('end_date', '>', now());
            })
            ->exists();
    }
}
