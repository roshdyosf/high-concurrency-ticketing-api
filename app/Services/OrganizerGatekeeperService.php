<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\GatekeeperInvitationStatus;
use App\Exceptions\EventNotInvitableException;
use App\Exceptions\GatekeeperAlreadyInvitedException;
use App\Exceptions\InviteeNotFoundException;
use App\Models\Event;
use App\Models\EventGatekeeper;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;

class OrganizerGatekeeperService
{
    public function invite(User $organizer, Event $event, string $email): EventGatekeeper
    {
        Gate::authorize('manage', $event);

        // Checked before the email lookup so a closed event never reveals whether an email exists.
        if (in_array($event->status, [EventStatus::Completed, EventStatus::Cancelled, EventStatus::Suspended], true)) {
            throw new EventNotInvitableException();
        }

        $invitee = User::query()->where('email', $email)->first();

        if ($invitee === null) {
            throw new InviteeNotFoundException();
        }

        $invitation = new EventGatekeeper();

        try {
            // The partial unique index gk_one_active is the atomic duplicate guard.
            $invitation->forceFill([
                'event_id' => $event->id,
                'user_id' => $invitee->id,
                'assigned_by' => $organizer->id,
                'assigned_at' => now(),
                'status' => GatekeeperInvitationStatus::Pending,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw new GatekeeperAlreadyInvitedException();
        }

        return $invitation;
    }
}
