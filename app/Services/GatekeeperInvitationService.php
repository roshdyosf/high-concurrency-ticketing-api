<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\GatekeeperInvitationStatus;
use App\Exceptions\InvitationEventCompletedException;
use App\Exceptions\InvitationNotPendingException;
use App\Models\EventGatekeeper;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

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

    public function accept(EventGatekeeper $invitation): EventGatekeeper
    {
        return $this->respond($invitation, GatekeeperInvitationStatus::Accepted);
    }

    private function respond(EventGatekeeper $invitation, GatekeeperInvitationStatus $target): EventGatekeeper
    {
        Gate::authorize('respond', $invitation);

        $invitation->loadMissing('event');

        if ($invitation->status !== GatekeeperInvitationStatus::Pending || $invitation->revoked_at !== null) {
            throw new InvitationNotPendingException();
        }

        if ($invitation->event->status === EventStatus::Completed) {
            throw new InvitationEventCompletedException();
        }

        // Compare-and-set: a concurrent revoke or response leaves zero affected rows.
        $updated = EventGatekeeper::query()
            ->whereKey($invitation->id)
            ->where('status', GatekeeperInvitationStatus::Pending->value)
            ->whereNull('revoked_at')
            ->update(['status' => $target->value]);

        if ($updated === 0) {
            throw new InvitationNotPendingException();
        }

        return $invitation->refresh()->load('event');
    }
}
