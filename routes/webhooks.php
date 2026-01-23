<?php

use App\Http\Controllers\Webhook\NelloBytesController;
use App\Http\Controllers\Webhook\VtpassController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Routes for handling webhook callbacks from external services.
| These routes do not require authentication.
|
*/

Route::match(['get', 'post'], 'nellobytes', [NelloBytesController::class, 'handleWebhook'])
    ->name('webhooks.nellobytes');

Route::match(['get', 'post'], 'vtpass', [VtpassController::class, 'handleWebhook'])
    ->name('webhooks.vtpass');
