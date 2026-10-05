<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class EmailNotVerifiedException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'EMAIL_NOT_VERIFIED',
            'message' => 'Your email address must be verified to perform this action.',
        ], 403);
    }
}
