<?php

use App\Http\Controllers\Api\V1\Account\OrganizerRequestController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Catalog\EventController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // public

    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware(['throttle:auth', 'throttle:login-email']);


    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password');
    Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password');

    Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')
        ->middleware('signed', 'throttle:public')
        ->name('verification.verify');

    Route::get('events', [EventController::class, 'index'])->middleware('throttle:public');
    // protected: auth:sanctum -> not.banned -> throttle:api
    Route::middleware(['auth:sanctum', 'not.banned', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/email/verification-notification', [EmailVerificationController::class, 'send']);
        Route::post('account/organizer-request', [OrganizerRequestController::class, 'store'])
            ->middleware('verified.email');
    });
});
