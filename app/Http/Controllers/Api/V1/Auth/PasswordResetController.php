<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $passwordReset,
    ) {
    }

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->sendResetLink($request->validated('email'));

        return response()->json([
            'message' => 'If the email exists, a password reset link has been sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->reset($request->validated());

        return response()->json(['message' => 'Password has been reset.']);
    }
}
