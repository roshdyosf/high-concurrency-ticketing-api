<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InvitationNotPendingException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'INVITATION_NOT_PENDING',
            'message' => 'This invitation is no longer pending.',
        ], 409);
    }
}
