<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\WaWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/wa/webhook', [WaWebhookController::class, 'verify'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.verify');
Route::post('/wa/webhook', [WaWebhookController::class, 'ingest'])
    ->withoutMiddleware('web')
    ->name('wa.webhook.ingest');

Route::middleware('wa.bearer')->group(function () {
    Route::get('/wa/suppressions', [MachineController::class, 'suppressions'])
        ->withoutMiddleware('web')
        ->name('wa.suppressions');
    Route::post('/inbound/record-send', [MachineController::class, 'recordSend'])
        ->withoutMiddleware('web')
        ->name('wa.record_send');
});

Route::get('/login', [LoginController::class, 'show'])->middleware('guest')->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect('/inbox'))->name('home');
});
