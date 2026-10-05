<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * Ownership check only. Role / approval / ban are enforced by middleware.
     */
    public function manage(User $user, Event $event): bool
    {
        return (int) $user->id === (int) $event->organizer_id;
    }
}
