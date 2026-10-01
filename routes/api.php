<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::get('ping', fn () => response()->json(['pong' => true]));

    Route::get('ip-test', fn (Request $r) => [
        'ip' => $r->ip(),
        'remote_addr' => $r->server('REMOTE_ADDR'),
        'xff_header' => $r->header('X-Forwarded-For'),
        'trusted_proxies' => Request::getTrustedProxies(),
        'env' => env('TRUSTED_PROXIES'),
    ]);
    Route::middleware('auth:sanctum')->get('auth-test', fn (Request $request) => $request->user());
});
