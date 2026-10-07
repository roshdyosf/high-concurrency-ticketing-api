<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\OrganizerRequestService;
use Illuminate\Http\Request;

class OrganizerRequestController extends Controller
{
    public function __construct(private readonly OrganizerRequestService $service)
    {
    }

    public function store(Request $request): UserResource
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return new UserResource($this->service->submit($user));
    }
}
