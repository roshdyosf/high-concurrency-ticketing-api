<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;

Route::prefix('v1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgot']);
    Route::post('auth/reset-password', [PasswordResetController::class, 'reset']);

    //authenticated users only
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/email/verification-notification', [EmailVerificationController::class, 'send']);


        //test routes for role middleware
        Route::get('_t/admin', fn() => ['ok' => 'admin'])->middleware('role:admin');
        Route::get('_t/organizer', fn() => ['ok' => 'organizer'])->middleware('role:organizer');
        Route::get('_t/customer', fn() => ['ok' => 'customer'])->middleware('role:customer');
    });
});
