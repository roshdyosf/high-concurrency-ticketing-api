<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

class OrganizerEventService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $organizer, array $data): Event
    {
        $event = new Event();
        $event->fill($data);
        $event->forceFill([
            'organizer_id' => $organizer->id,
            'status' => EventStatus::Draft,
        ])->save();

        return $event;
    }
}
