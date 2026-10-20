<?php

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\InviteGatekeeperRequest;
use App\Http\Resources\GatekeeperInvitationResource;
use App\Models\Event;
use App\Models\User;
use App\Services\OrganizerGatekeeperService;
use Illuminate\Http\JsonResponse;

class GatekeeperController extends Controller
{
    public function __construct(private readonly OrganizerGatekeeperService $gatekeepers)
    {
    }

    public function store(InviteGatekeeperRequest $request, Event $event): JsonResponse
    {
        /** @var User $organizer */
        $organizer = $request->user();

        $invitation = $this->gatekeepers->invite($organizer, $event, (string) $request->validated('email'));

        return (new GatekeeperInvitationResource($invitation))
            ->response()
            ->setStatusCode(201);
    }
}
