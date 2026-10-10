<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::group([], __DIR__ . '/api/auth.php');
    Route::group([], __DIR__ . '/api/account.php');
    Route::group([], __DIR__ . '/api/catalog.php');
    Route::group([], __DIR__ . '/api/organizer.php');
});
