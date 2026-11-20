<?php

use App\Http\Controllers\Telegram\WebhookController;
use Illuminate\Support\Facades\Route;

// The only public integration route. Secret-verified, rate limited, idempotent.
Route::post('/telegram/webhook', WebhookController::class)
    ->middleware('throttle:600,1')
    ->name('telegram.webhook');
