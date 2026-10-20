<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\GatekeeperInvitationStatus;
use App\Models\EventGatekeeper;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class GatekeeperInvitationService
{
    /**
     * @return Collection<int, EventGatekeeper>
     */
    public function listPending(User $user): Collection
    {
        return EventGatekeeper::query()
            ->where('user_id', $user->id)
            ->where('status', GatekeeperInvitationStatus::Pending->value)
            ->whereNull('revoked_at')
            ->whereHas('event', function ($query) {
                $query->where('status', '!=', EventStatus::Completed->value);
            })
            ->with('event')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();
    }
}
