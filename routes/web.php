<?php

use App\Http\Controllers\PaystackCheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('https://paxi.ng');
});

// Paystack redirects the user's browser here after payment (unauthenticated web route)
Route::get('/paystack/callback', [PaystackCheckoutController::class, 'callback']);
