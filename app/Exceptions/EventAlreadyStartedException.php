<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class EventAlreadyStartedException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'EVENT_ALREADY_STARTED',
            'message' => 'An event that has already started cannot be published.',
        ], 422);
    }
}
