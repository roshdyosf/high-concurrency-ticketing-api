<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class EventHasNoTiersException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'EVENT_HAS_NO_TIERS',
            'message' => 'Add at least one ticket tier before publishing.',
        ], 422);
    }
}
