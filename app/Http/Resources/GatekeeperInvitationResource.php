<?php

namespace App\Http\Resources;

use App\Models\EventGatekeeper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventGatekeeper
 */
class GatekeeperInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'assigned_at' => $this->assigned_at->toIso8601String(),
            'event' => $this->whenLoaded('event', fn (): array => [
                'id' => $this->event->id,
                'title' => $this->event->title,
                'venue_name' => $this->event->venue_name,
                'location' => $this->event->location,
                'event_date' => $this->event->event_date->toIso8601String(),
                'end_date' => $this->event->end_date->toIso8601String(),
            ]),
        ];
    }
}
