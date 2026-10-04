<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class AccountBannedException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'ACCOUNT_BANNED',
            'message' => 'This account has been banned.',
        ], 403);
    }
}
