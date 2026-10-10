<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\TierType;
use App\Exceptions\EventNotEditableException;
use App\Models\Event;
use App\Models\TicketTier;
use Illuminate\Support\Facades\Gate;

class OrganizerTierService
{
    public function __construct(private readonly GaCapacityService $capacity)
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Event $event, array $data): TicketTier
    {
        Gate::authorize('manage', $event);

        if (! in_array($event->status, [EventStatus::Draft, EventStatus::Published], true)) {
            throw new EventNotEditableException();
        }

        $type = TierType::from((string) $data['type']);
        $isGa = $type === TierType::GeneralAdmission;

        $tier = $event->ticketTiers()->create([
            'name' => $data['name'],
            'type' => $type,
            'price' => $data['price'],
            'total_capacity' => $isGa ? (int) $data['total_capacity'] : 0,
        ]);

        if ($isGa) {
            $this->capacity->initialize($tier);
        }

        return $tier;
    }
}
