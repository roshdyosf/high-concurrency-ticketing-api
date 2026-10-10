<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class SeatsAlreadyGeneratedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Seats were already generated for this tier.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'SEATS_ALREADY_GENERATED',
            'message' => $this->getMessage(),
        ], 409);
    }
}
