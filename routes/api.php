<?php

use App\Http\Controllers\AirtimeController;
use App\Http\Controllers\Api\Admin\EpinController as AdminEpinController;
use App\Http\Controllers\Api\AdminCronController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\AuthorizationController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BankTransferController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\Vtpass\InternationalAirtimeController as VtpassIntlAirtimeController;
use App\Http\Controllers\Api\V1\Vtpass\SmileController as VtpassSmileController;
use App\Http\Controllers\Api\V1\Vtpass\SpectranetController as VtpassSpectranetController;
use App\Http\Controllers\Api\VirtualAccountController;
use App\Http\Controllers\Api\WithdrawController;
use App\Http\Controllers\CableTvController;
use App\Http\Controllers\DataController;
use App\Http\Controllers\ElectricityController;
use App\Http\Controllers\ExamCardController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\GeneralController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\PaystackCheckoutController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(function () {
    Route::post('/register', 'register')->middleware(['signup.ip.guard', 'check.system.status:signup']);
    Route::post('/login', 'login')->middleware('check.system.status:login');
})->middleware(['throttle:6,1']);

Route::post('login/verify-device', [AuthController::class, 'verifyDevice'])->middleware('throttle:10,5', 'check.system.status:login');
Route::post('login/resend-device-otp', [AuthController::class, 'resendDeviceOtp'])->middleware('throttle:3,10', 'check.system.status:login');
Route::controller(ForgotPasswordController::class)->group(function () {
    Route::post('password/email', 'sendResetCodeEmail')->middleware(['throttle.verification:password', 'throttle:3,60']);
    Route::post('password/verify-code', 'verifyCode')->middleware('throttle:10,120');
    Route::post('password/reset', 'reset')->middleware('throttle:5,60');
});
Route::get('check-username/{username}', [UserController::class, 'checkUsername'])->middleware('throttle:30,1');
Route::post('verify-email', [AuthorizationController::class, 'emailVerification'])->middleware('throttle:10,240');
Route::post('resend-verify/{type}', [AuthorizationController::class, 'sendVerifyCode'])->middleware(['throttle.verification:email', 'throttle:3,60']);
Route::post('send-sms-code', [AuthorizationController::class, 'sendSmsVerificationCode'])->middleware('throttle:5,240');
Route::post('verify-sms-code', [AuthorizationController::class, 'mobileVerification'])->middleware('throttle:10,240');

// Allow authenticated users to change phone before verification
Route::post('change-phone', [UserController::class, 'changePhoneNumber'])->middleware(['auth:sanctum', 'throttle:5,60']);
Route::post('changepin', UserController::class.'@changePin')->middleware(['auth:sanctum', 'throttle:5,60']);

Route::middleware(['auth:sanctum', 'check.status'])->group(function () {
    Route::post('logout', AuthController::class.'@logout');

    // Trust token management (list + revoke)
    Route::get('user/trust-tokens', [AuthController::class, 'listTrustTokens']);
    Route::delete('user/trust-tokens/{id}', [AuthController::class, 'revokeTrustToken']);

    // authorization
    Route::controller(AuthorizationController::class)->group(function () {
        Route::get('authorization', 'authorization');
    });
    // User
    Route::apiResource('user', UserController::class);
    Route::post('wallet-transfer', [UserController::class, 'walletTransfer'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
    Route::post('users/set-username', [UserController::class, 'setUsername']);
    Route::get('referral-leaderboard', [UserController::class, 'referralLeaderboard']);
    Route::post('referral/payout', [UserController::class, 'referralPayout'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
    // Transactions
    Route::get('transactions', [TransactionController::class, 'index']);
    // Change password
    Route::post('changepass', UserController::class.'@changePassword');

    // Virtual Account
    Route::post('create-virtual-account', [VirtualAccountController::class, 'create']);

    // Paystack Instant Funding (Checkout)
    Route::post('paystack/checkout/initialize', [PaystackCheckoutController::class, 'initialize'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
    Route::get('paystack/checkout/verify/{reference}', [PaystackCheckoutController::class, 'verify']);

    // PalmPay Bank Transfer
    Route::post('bank-transfer/initiate', [BankTransferController::class, 'initiate'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
    Route::get('bank-transfer/status/{orderId}', [BankTransferController::class, 'status']);

    // Bank Accounts (PalmPay Payout)
    Route::get('banks', [BankAccountController::class, 'getBanks']);
    Route::post('banks/verify-account', [BankAccountController::class, 'verifyAccount']);
    Route::get('bank-accounts', [BankAccountController::class, 'index']);
    Route::post('bank-accounts', [BankAccountController::class, 'store']);
    Route::patch('bank-accounts/{id}/default', [BankAccountController::class, 'setDefault']);
    Route::delete('bank-accounts/{id}', [BankAccountController::class, 'destroy']);

    // Withdraw to Bank (PalmPay Payout)
    Route::post('withdraw', [WithdrawController::class, 'withdraw'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);

    // Data
    Route::controller(DataController::class)->group(function () {
        Route::prefix('data')->group(function () {
            Route::get('/', 'data');
            Route::post('/', 'purchaseData')->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
        });
    });
    // Electricity
    Route::controller(ElectricityController::class)->group(function () {
        Route::prefix('electricity')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'purchaseElectricity')->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
            Route::get('/history', 'purchaseHistory');
            Route::post('/verify-meter', 'verifyMeterNo');
        });
    });
    // Airtime
    Route::prefix('airtime')->group(function () {
        Route::get('/', [AirtimeController::class, 'index']);
        Route::post('/', [AirtimeController::class, 'purchaseAirtime'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
    });
    // Tv cable
    Route::controller(CableTvController::class)->group(function () {
        Route::prefix('cable')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'purchaseCableTv')->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
            Route::post('/verify', 'verifyIUC');
        });
    });
    // Exam Card
    Route::prefix('exam-card')->group(function () {
        Route::get('/', [ExamCardController::class, 'index']);
        Route::get('/history', [ExamCardController::class, 'purchaseHistory']);
        Route::post('/', [ExamCardController::class, 'purchaseExamCardPin'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
    });
    // Settings
    Route::controller(GeneralController::class)->group(function () {
        Route::post('verify-network', 'verifyNetwork')->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
        Route::post('agent', 'agent')->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
        Route::post('vendor', 'vendor')->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
        Route::get('support', 'supportInfo');
        Route::post('support', 'support');
    });

    // KYC
    Route::get('kyc/status/{job_id}', [KycController::class, 'status']);
    Route::post('kyc/initiate', [KycController::class, 'initiate']);

    // Feedback
    Route::prefix('feedback')->group(function () {
        Route::get('/', [FeedbackController::class, 'index']);
        Route::post('/', [FeedbackController::class, 'store']);
        Route::get('/{id}', [FeedbackController::class, 'show']);
    });

    // VTpass Integration
    Route::prefix('vtpass')->group(function () {
        // Airtime (Refactored to existing controller)
        // Data (Refactored to existing controller)
        // TV Subscription (Refactored to existing controller)

        // Smile
        Route::post('smile/verify', [VtpassSmileController::class, 'verify']);
        Route::get('smile/bundles', [VtpassSmileController::class, 'getBundles']);
        Route::post('smile/purchase', [VtpassSmileController::class, 'purchase'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);

        // Spectranet
        Route::post('spectranet/verify', [VtpassSpectranetController::class, 'verify']);
        Route::get('spectranet/bundles', [VtpassSpectranetController::class, 'getBundles']);
        Route::post('spectranet/purchase', [VtpassSpectranetController::class, 'purchase'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);

        // International Airtime
        Route::get('international-airtime/countries', [VtpassIntlAirtimeController::class, 'getCountries']);
        Route::get('international-airtime/product-types', [VtpassIntlAirtimeController::class, 'getProductTypes']);
        Route::get('international-airtime/operators', [VtpassIntlAirtimeController::class, 'getOperators']);
        Route::get('international-airtime/variations', [VtpassIntlAirtimeController::class, 'getVariations']);
        Route::post('international-airtime/purchase', [VtpassIntlAirtimeController::class, 'purchase'])->middleware(['pnd.check', 'check.system.status:transactions', 'verified.user', 'txn.burst.guard', 'txn.daily.limit']);
    });
});

Route::get('settings', [GeneralController::class, 'settings']);
Route::get('ad-banner', [GeneralController::class, 'adBanner']);

// Admin-only cron triggers (protected by shared secret, not user auth)
Route::post('admin/cron/referral-signup-bonus', [AdminCronController::class, 'runReferralSignupBonus']);

// Admin EPIN management (protected by shared secret)
Route::prefix('admin/epin')->middleware('admin.secret')->group(function () {
    Route::post('buy-bulk', [AdminEpinController::class, 'buyBulk']);
    Route::post('manual-add', [AdminEpinController::class, 'manualAdd']);
    Route::post('check-duplicates', [AdminEpinController::class, 'checkDuplicates']);
    Route::get('stats', [AdminEpinController::class, 'stats']);
    Route::get('inventory', [AdminEpinController::class, 'inventory']);
    Route::get('batches', [AdminEpinController::class, 'batches']);
    Route::post('batches/{ref}/sync', [AdminEpinController::class, 'syncBatch']);
    Route::post('toggle-source', [AdminEpinController::class, 'toggleSource']);
    Route::get('source-mode', [AdminEpinController::class, 'getSourceMode']);
});

// Webhooks (Public but Signed)
Route::post('webhooks/smile-identity', [KycController::class, 'handleWebhook']);
Route::post('smile-callback', [KycController::class, 'handleWebhook']);
