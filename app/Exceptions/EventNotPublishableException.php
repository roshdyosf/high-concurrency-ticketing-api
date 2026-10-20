<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class EventNotPublishableException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'EVENT_NOT_PUBLISHABLE',
            'message' => 'Only a draft event can be published.',
        ], 409);
    }
}
