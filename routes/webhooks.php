<?php

use App\Http\Controllers\Webhook\NelloBytesController;
use App\Http\Controllers\Webhook\PalmpayBankTransferWebhookController;
use App\Http\Controllers\Webhook\PalmpayPayoutWebhookController;
use App\Http\Controllers\Webhook\PalmpayVirtualAccountController;
use App\Http\Controllers\Webhook\PaystackWebhookController;
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

Route::post('palmpay/virtual-account', [PalmpayVirtualAccountController::class, 'handleWebhook'])
    ->name('webhooks.palmpay.virtual-account');

Route::post('palmpay/bank-transfer', [PalmpayBankTransferWebhookController::class, 'handleWebhook'])
    ->name('webhooks.palmpay.bank-transfer');

Route::post('palmpay/payout', [PalmpayPayoutWebhookController::class, 'handleWebhook'])
    ->name('webhooks.palmpay.payout');

Route::post('paystack', [PaystackWebhookController::class, 'handleWebhook'])
    ->name('webhooks.paystack');
