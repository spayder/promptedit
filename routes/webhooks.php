<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

// Deliberately outside the "web" group: no session, no cookies, no CSRF token.
// Pixelforge is a server calling us; the signature is what authenticates it.
Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
