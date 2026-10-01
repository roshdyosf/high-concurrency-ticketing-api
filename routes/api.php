<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\RegisterController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::get('ping', fn () => response()->json(['pong' => true]));

    Route::post('auth/register', RegisterController::class);

    Route::middleware('auth:sanctum')->get('auth-test', fn (Request $request) => $request->user());
});
