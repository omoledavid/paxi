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
use Illuminate\Support\Facades\Route;



Route::controller(AuthController::class)->group(function () {
    Route::post('/register', 'register');
    Route::post('/login', 'login');
});
Route::controller(ForgotPasswordController::class)->group(function () {
    Route::post('password/email', 'sendResetCodeEmail');
    Route::post('password/verify-code', 'verifyCode');
    Route::post('password/reset', 'reset');
});
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('logout', AuthController::class . '@logout');
    //authorization
    Route::controller(AuthorizationController::class)->group(function () {
        Route::get('authorization', 'authorization');
        Route::get('resend-verify/{type}', 'sendVerifyCode');
        Route::post('verify-email', 'emailVerification');
        Route::post('verify-mobile', 'mobileVerification');
    });
    Route::apiResource('user', UserController::class);
    //
    Route::controller(DataController::class)->group(function () {
        Route::prefix('data')->group(function () {
            Route::get('/', 'data');
        });
    });
    //Electricity
    Route::controller(ElectricityController::class)->group(function () {
        Route::prefix('electricity')->group(function () {
            Route::get('/', 'index');
        });
    });
    //Airtime
    Route::controller(AirtimeController::class)->group(function () {
        Route::prefix('airtime')->group(function () {
            Route::get('/', 'index');
        });
    });
    //Tv cable
    Route::controller(CableTvController::class)->group(function () {
        Route::prefix('cable')->group(function () {
            Route::get('/', 'index');
        });
    });
    //Exam Card
    Route::controller(ExamCardController::class)->group(function () {
        Route::prefix('exam-card')->group(function () {
            Route::get('/', 'index');
        });
    });
    Route::controller(GeneralController::class)->group(function () {
        Route::post('verify-network', 'verifyNetwork');
        Route::post('agent', 'agent');
        Route::post('vendor', 'vendor');
        Route::get('support', 'supportInfo');
        Route::post('support', 'support');
    });
});
