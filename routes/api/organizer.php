<?php

use App\Http\Controllers\Api\V1\Organizer\EventController;
use App\Http\Controllers\Api\V1\Organizer\GatekeeperController;
use App\Http\Controllers\Api\V1\Organizer\SeatController;
use App\Http\Controllers\Api\V1\Organizer\TierController;
use Illuminate\Support\Facades\Route;

// protected: auth:sanctum -> not.banned -> throttle:api -> role:organizer (approval is checked inside role)
Route::middleware(['auth:sanctum', 'not.banned', 'throttle:api', 'role:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::post('events', [EventController::class, 'store']);
        Route::post('events/{event}/publish', [EventController::class, 'publish'])->whereNumber('event');
        Route::post('events/{event}/tiers', [TierController::class, 'store'])->whereNumber('event');
        Route::post('tiers/{tier}/seats/bulk', [SeatController::class, 'bulk'])->whereNumber('tier');
    });

// dedicated limiter instead of throttle:api, same order (decision 56)
Route::middleware(['auth:sanctum', 'not.banned', 'throttle:invites', 'role:organizer'])
    ->prefix('organizer')
    ->group(function () {
        Route::post('events/{event}/gatekeepers', [GatekeeperController::class, 'store'])->whereNumber('event');
    });
