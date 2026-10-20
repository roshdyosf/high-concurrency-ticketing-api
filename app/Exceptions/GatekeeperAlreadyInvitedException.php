<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class GatekeeperAlreadyInvitedException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'GATEKEEPER_ALREADY_INVITED',
            'message' => 'This user already has an active invitation or assignment for the event.',
        ], 409);
    }
}
