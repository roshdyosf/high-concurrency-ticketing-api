<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InvitationEventCompletedException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'INVITATION_EVENT_COMPLETED',
            'message' => 'The event of this invitation has already completed.',
        ], 409);
    }
}
