<?php

namespace App\Policies;

use App\Models\EventGatekeeper;
use App\Models\User;

class EventGatekeeperPolicy
{
    /**
     * Only the invited user may accept or reject an invitation.
     */
    public function respond(User $user, EventGatekeeper $invitation): bool
    {
        return $invitation->user_id === $user->id;
    }
}
