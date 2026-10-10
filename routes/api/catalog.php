<?php

use App\Http\Controllers\Api\V1\Catalog\EventController;
use Illuminate\Support\Facades\Route;

// public
Route::get('events', [EventController::class, 'index'])->middleware('throttle:public');

Route::get('events/{id}', [EventController::class, 'show'])
    ->whereNumber('id')
    ->middleware('throttle:public');

Route::get('events/{id}/seats', [EventController::class, 'seats'])
    ->whereNumber('id')
    ->middleware('throttle:public');

Route::get('events/{id}/tiers/{tierId}/seats/contiguous', [EventController::class, 'contiguous'])
    ->whereNumber(['id', 'tierId'])
    ->middleware('throttle:public');
