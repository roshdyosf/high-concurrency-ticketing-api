<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InvalidSeatSelectionException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'INVALID_SEAT_SELECTION',
            'message' => $this->getMessage(),
        ], 422);
    }
}
