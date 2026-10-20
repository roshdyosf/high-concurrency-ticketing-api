<?php

use App\Http\Controllers\Api\V1\Account\GatekeeperInvitationController;
use App\Http\Controllers\Api\V1\Account\OrganizerRequestController;
use Illuminate\Support\Facades\Route;

// protected: auth:sanctum -> not.banned -> throttle:api -> verified.email
Route::middleware(['auth:sanctum', 'not.banned', 'throttle:api'])->group(function () {
    Route::post('account/organizer-request', [OrganizerRequestController::class, 'store'])
        ->middleware('verified.email');
});

// Account & invitations: any authenticated non-banned user (no role, no verified.email)
Route::middleware(['auth:sanctum', 'not.banned', 'throttle:api'])
    ->group(function () {
        Route::get('gatekeeper-invitations', [GatekeeperInvitationController::class, 'index']);
        Route::post('gatekeeper-invitations/{invitation}/accept', [GatekeeperInvitationController::class, 'accept'])->whereNumber('invitation');
        Route::post('gatekeeper-invitations/{invitation}/reject', [GatekeeperInvitationController::class, 'reject'])->whereNumber('invitation');
    });
