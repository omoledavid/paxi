<?php

use App\Http\Controllers\Api\V1\NelloBytes\BettingController;
use App\Http\Controllers\Api\V1\NelloBytes\DataController;
use App\Http\Controllers\Api\V1\NelloBytes\ElectricityController;
use App\Http\Controllers\Api\V1\NelloBytes\EpinController;
use App\Http\Controllers\Api\V1\NelloBytes\SmileController;
use App\Http\Controllers\Api\V1\NelloBytes\SpectranetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| NelloBytes API Routes
|--------------------------------------------------------------------------
|
| Routes for NelloBytes API integration (Betting, EPIN, Smile, Spectranet)
| All routes require authentication via Sanctum.
|
*/

Route::middleware(['auth:sanctum', 'check.status'])->group(function () {
    // Betting routes
    Route::prefix('betting')->group(function () {
        Route::get('companies', [BettingController::class, 'getCompanies']);
        Route::post('verify', [BettingController::class, 'verifyCustomer']);
        Route::post('fund', [BettingController::class, 'fund']);
    });

    // EPIN routes
    Route::prefix('epin')->group(function () {
        Route::get('discounts', [EpinController::class, 'getDiscounts']);
        Route::post('print', [EpinController::class, 'printCard']);
        Route::get('query', [EpinController::class, 'query']);
    });

    // Smile routes
    Route::prefix('smile')->group(function () {
        Route::get('packages', [SmileController::class, 'getPackages']);
        Route::post('verify', [SmileController::class, 'verify']);
        Route::post('buy', [SmileController::class, 'buyBundle']);
    });

    // Spectranet routes
    Route::prefix('spectranet')->group(function () {
        Route::get('packages', [SpectranetController::class, 'getPackages']);
        Route::post('buy', [SpectranetController::class, 'buyBundle']);
    });

    //Data
    Route::prefix('data')->group(function(){
        Route::get('dataplan', [DataController::class, 'getDataplan']);
        Route::post('buy', [DataController::class, 'buyData']);
    });

    //Electricity
    Route::prefix('electricity')->group(function(){
        Route::get('providers', [ElectricityController::class, 'getProviders']);
        Route::post('buy', [ElectricityController::class, 'buyElectricity']);
    });
});

