<?php

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\CreateTierRequest;
use App\Http\Resources\TicketTierResource;
use App\Models\Event;
use App\Services\OrganizerTierService;
use Illuminate\Http\JsonResponse;

class TierController extends Controller
{
    public function __construct(private readonly OrganizerTierService $tiers)
    {
    }

    public function store(CreateTierRequest $request, Event $event): JsonResponse
    {
        return (new TicketTierResource($this->tiers->create($event, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }
}
