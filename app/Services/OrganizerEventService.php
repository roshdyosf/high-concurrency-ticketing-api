<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Exceptions\EventAlreadyStartedException;
use App\Exceptions\EventHasNoTiersException;
use App\Exceptions\EventNotPublishableException;
use App\Models\Event;
use App\Models\TicketTier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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

    public function publish(Event $event): Event
    {
        Gate::authorize('manage', $event);

        return DB::transaction(function () use ($event): Event {
            // Lock the row so two concurrent publish calls cannot interleave.
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EventStatus::Draft) {
                throw new EventNotPublishableException();
            }

            if (! TicketTier::query()->where('event_id', $locked->id)->exists()) {
                throw new EventHasNoTiersException();
            }

            if ($locked->event_date->isPast()) {
                throw new EventAlreadyStartedException();
            }

            $locked->forceFill(['status' => EventStatus::Published])->save();

            return $locked;
        });
    }
}
