<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class TierNotSeatedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Seats can only be generated for a seated tier.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'TIER_NOT_SEATED',
            'message' => $this->getMessage(),
        ], 422);
    }
}
