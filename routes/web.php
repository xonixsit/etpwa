<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InboxController;
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
    Route::get('/inbox', [InboxController::class, 'contacts'])->name('inbox');
    Route::get('/inbox/{contact}', [InboxController::class, 'thread'])->name('inbox.thread');
    Route::post('/inbox/{contact}/reply', [InboxController::class, 'reply'])->name('inbox.reply');
    Route::post('/inbox/{contact}/template', [InboxController::class, 'template'])->name('inbox.template');
    Route::post('/inbox/{contact}/opt-out', [InboxController::class, 'optOut'])->name('inbox.optout');
});
