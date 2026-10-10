<?php

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\CreateEventRequest;
use App\Http\Resources\OrganizerEventResource;
use App\Models\User;
use App\Services\OrganizerEventService;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    public function __construct(private readonly OrganizerEventService $events)
    {
    }

    public function store(CreateEventRequest $request): JsonResponse
    {
        /** @var User $organizer */
        $organizer = $request->user();

        return (new OrganizerEventResource($this->events->create($organizer, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }
}
