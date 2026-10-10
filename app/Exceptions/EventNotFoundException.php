<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class EventNotFoundException extends Exception
{
    public function __construct()
    {
        parent::__construct('Event not found.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'EVENT_NOT_FOUND',
            'message' => $this->getMessage(),
        ], 404);
    }
}
