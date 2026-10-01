<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::get('ping', fn () => response()->json(['pong' => true]));

    Route::middleware('auth:sanctum')->get('auth-test', fn (Request $request) => $request->user());
});
