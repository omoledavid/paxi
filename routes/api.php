<?php

use App\Http\Controllers\AirtimeController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\AuthorizationController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\CableTvController;
use App\Http\Controllers\DataController;
use App\Http\Controllers\ElectricityController;
use App\Http\Controllers\ExamCardController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;



Route::controller(AuthController::class)->group(function () {
    Route::post('/register', 'register');
    Route::post('/login', 'login');
})->middleware(['throttle:6,1']);
Route::controller(ForgotPasswordController::class)->group(function () {
    Route::post('password/email', 'sendResetCodeEmail')->middleware(['throttle.verification:password', 'throttle:3,60']);
    Route::post('password/verify-code', 'verifyCode')->middleware('throttle:10,60');
    Route::post('password/reset', 'reset')->middleware('throttle:5,60');
});
Route::post('verify-email', [AuthorizationController::class, 'emailVerification'])->middleware('throttle:10,60');
Route::post('resend-verify/{type}', [AuthorizationController::class, 'sendVerifyCode'])->middleware(['throttle.verification:email', 'throttle:3,60']);
Route::post('send-sms-code', [AuthorizationController::class, 'sendSmsVerificationCode'])->middleware('throttle:3,60');
Route::post('verify-sms-code', [AuthorizationController::class, 'mobileVerification'])->middleware('throttle:10,60');

// Allow authenticated users to change phone before verification
Route::post('change-phone', [UserController::class, 'changePhoneNumber'])->middleware(['auth:sanctum', 'throttle:5,60']);

Route::middleware(['auth:sanctum', 'check.status'])->group(function () {
    Route::post('logout', AuthController::class . '@logout');
    //authorization
    Route::controller(AuthorizationController::class)->group(function () {
        Route::get('authorization', 'authorization');
    });
    //User
    Route::apiResource('user', UserController::class);
    Route::post('wallet-transfer', [UserController::class, 'walletTransfer']);
    //Transactions
    Route::get('transactions', [TransactionController::class, 'index']);
    //Change password
    Route::post('changepass', UserController::class . '@changePassword');
    Route::post('changepin', UserController::class . '@changePin');

    //Data
    Route::controller(DataController::class)->group(function () {
        Route::prefix('data')->group(function () {
            Route::get('/', 'data');
            Route::post('/', 'purchaseData');
        });
    });
    //Electricity
    Route::controller(ElectricityController::class)->group(function () {
        Route::prefix('electricity')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'purchaseElectricity');
            Route::get('/history', 'purchaseHistory');
            Route::post('/verify-meter', 'verifyMeterNo');
        });
    });
    //Airtime
    Route::controller(AirtimeController::class)->group(function () {
        Route::prefix('airtime')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'purchaseAirtime');
        });
    });
    //Tv cable
    Route::controller(CableTvController::class)->group(function () {
        Route::prefix('cable')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'purchaseCableTv');
            Route::post('/verify', 'verifyIUC');
        });
    });
    //Exam Card
    Route::controller(ExamCardController::class)->group(function () {
        Route::prefix('exam-card')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'purchaseExamCardPin');
        });
    });
    Route::controller(GeneralController::class)->group(function () {
        Route::post('verify-network', 'verifyNetwork');
        Route::post('agent', 'agent');
        Route::post('vendor', 'vendor');
        Route::get('support', 'supportInfo');
        Route::post('support', 'support');
        Route::get('settings', 'settings');
    });
});
