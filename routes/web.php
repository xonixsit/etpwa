<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\WaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/wa/webhook', [WaWebhookController::class, 'verify'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.verify');
Route::post('/wa/webhook', [WaWebhookController::class, 'ingest'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.ingest');

Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect('/inbox'))->name('home');
});
