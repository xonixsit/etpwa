<?php

use App\Http\Controllers\WaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/wa/webhook', [WaWebhookController::class, 'verify'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.verify');

Route::post('/wa/webhook', [WaWebhookController::class, 'ingest'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.ingest');
