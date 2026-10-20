<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\GatekeeperInvitationResource;
use App\Models\User;
use App\Models\EventGatekeeper;
use App\Services\GatekeeperInvitationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GatekeeperInvitationController extends Controller
{
    public function __construct(private readonly GatekeeperInvitationService $invitations)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return GatekeeperInvitationResource::collection($this->invitations->listPending($user));
    }
    public function accept(EventGatekeeper $invitation): GatekeeperInvitationResource
    {
        return new GatekeeperInvitationResource($this->invitations->accept($invitation));
    }
}
