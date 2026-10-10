<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'venue_name' => $this->venue_name,
            'location' => $this->location,
            'event_date' => $this->event_date->toIso8601String(),
            'end_date' => $this->end_date->toIso8601String(),
            'image_url' => $this->image_url,
            'organizer' => $this->whenLoaded('organizer', fn () => [
                'id' => $this->organizer->id,
                'name' => $this->organizer->name,
            ]),
            'tiers' => TicketTierResource::collection($this->whenLoaded('ticketTiers')),
        ];
    }
}
