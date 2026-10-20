<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class EventNotInvitableException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'EVENT_NOT_INVITABLE',
            'message' => 'Gatekeepers cannot be invited to a completed, cancelled or suspended event.',
        ], 409);
    }
}
