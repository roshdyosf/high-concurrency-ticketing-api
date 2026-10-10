<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class TierNotFoundException extends Exception
{
    public function __construct()
    {
        parent::__construct('Ticket tier not found.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'TIER_NOT_FOUND',
            'message' => $this->getMessage(),
        ], 404);
    }
}
