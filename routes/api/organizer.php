<?php

use App\Http\Controllers\Api\V1\Organizer\EventController;
use Illuminate\Support\Facades\Route;

// protected: auth:sanctum -> not.banned -> throttle:api -> role:organizer (approval is checked inside role)
Route::middleware(['auth:sanctum', 'not.banned', 'throttle:api', 'role:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::post('events', [EventController::class, 'store']);
    });
