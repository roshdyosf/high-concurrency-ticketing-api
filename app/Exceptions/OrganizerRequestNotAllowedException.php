<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class OrganizerRequestNotAllowedException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'ORGANIZER_REQUEST_NOT_ALLOWED',
            'message' => 'Only customers can request organizer status.',
        ], 409);
    }
}
