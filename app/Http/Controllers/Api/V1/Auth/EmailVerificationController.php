<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $emailVerification)
    {
    }


    public function send(Request $request): JsonResponse
    {
        $this->emailVerification->sendNotification($request->user());

        return response()->json(['message' => 'Verification link sent.']);
    }

    public function verify(int $id, string $hash): JsonResponse
    {
        $this->emailVerification->verify($id, $hash);

        return response()->json(['message' => 'Email verified.']);
    }
}
