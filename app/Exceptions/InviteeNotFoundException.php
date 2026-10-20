<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InviteeNotFoundException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'USER_NOT_FOUND',
            'message' => 'No registered user has this email.',
        ], 404);
    }
}
