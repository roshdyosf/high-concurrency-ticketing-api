<?php

use App\Http\Controllers\Api\V1\Account\OrganizerRequestController;
use Illuminate\Support\Facades\Route;

// protected: auth:sanctum -> not.banned -> throttle:api -> verified.email
Route::middleware(['auth:sanctum', 'not.banned', 'throttle:api'])->group(function () {
    Route::post('account/organizer-request', [OrganizerRequestController::class, 'store'])
        ->middleware('verified.email');
});
