<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class SeatsUnavailableException extends Exception
{
    public function __construct()
    {
        parent::__construct('One or more of the selected seats are no longer available.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'SEATS_UNAVAILABLE',
            'message' => $this->getMessage(),
        ], 409);
    }
}
