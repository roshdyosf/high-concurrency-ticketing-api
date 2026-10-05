<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;


class InvalidVerificationLinkException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'INVALID_VERIFICATION_LINK',
            'message' => 'The verification link is invalid.',
        ], 403);
    }
}
