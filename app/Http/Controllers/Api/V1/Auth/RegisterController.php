<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = new User($request->validated());
        $user->role = UserRole::Customer;
        $user->is_approved = true;
        $user->save();

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }
}
