<?php

use App\Http\Controllers\WaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/wa/webhook', [WaWebhookController::class, 'verify'])->name('wa.webhook.verify');
