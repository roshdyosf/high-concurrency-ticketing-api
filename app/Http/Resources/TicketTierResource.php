<?php

namespace App\Http\Resources;

use App\Models\TicketTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketTier
 */
class TicketTierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'price' => $this->price,
            'total_capacity' => $this->total_capacity,
        ];
    }
}
