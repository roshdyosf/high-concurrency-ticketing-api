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
        ];
    }
}
