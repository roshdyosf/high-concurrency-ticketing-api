<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class EmailAlreadyVerifiedException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'EMAIL_ALREADY_VERIFIED',
            'message' => 'Email is already verified.',
        ], 409);
    }
}
