<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class EventNotEditableException extends Exception
{
    public function __construct()
    {
        parent::__construct('This event can no longer be modified.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'EVENT_NOT_EDITABLE',
            'message' => $this->getMessage(),
        ], 409);
    }
}
