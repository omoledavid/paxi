<?php

use App\Enums\PalmpayServiceType;
use App\Enums\TransactionStatus;
use App\Models\PalmpayTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

// --- PalmpayServiceType Enum Tests ---

it('has the correct palmpay service type values', function () {
    expect(PalmpayServiceType::AIRTIME->value)->toBe('airtime');
    expect(PalmpayServiceType::DATA->value)->toBe('data');
    expect(PalmpayServiceType::BETTING->value)->toBe('betting');
});

it('can create PalmpayServiceType from string', function () {
    expect(PalmpayServiceType::from('airtime'))->toBe(PalmpayServiceType::AIRTIME);
    expect(PalmpayServiceType::from('data'))->toBe(PalmpayServiceType::DATA);
    expect(PalmpayServiceType::from('betting'))->toBe(PalmpayServiceType::BETTING);
});

it('has exactly 3 palmpay service types', function () {
    expect(PalmpayServiceType::cases())->toHaveCount(3);
});

// --- Config Tests ---

it('loads palmpay config file', function () {
    $config = config('palmpay');

    expect($config)->toBeArray();
    expect($config)->toHaveKeys([
        'base_url',
        'sandbox_base_url',
        'use_sandbox',
        'timeout',
        'retry',
        'endpoints',
        'network_map',
        'merchant_private_key',
        'platform_public_key',
        'notify_url',
    ]);
});

it('has correct palmpay endpoint keys', function () {
    $endpoints = config('palmpay.endpoints');

    expect($endpoints)->toHaveKeys([
        'query_biller',
        'query_item',
        'create_order',
        'query_order',
        'query_recharge_account',
    ]);
});

it('has correct palmpay endpoint paths', function () {
    $endpoints = config('palmpay.endpoints');

    expect($endpoints['query_biller'])->toBe('biller/query');
    expect($endpoints['query_item'])->toBe('item/query');
    expect($endpoints['create_order'])->toBe('order/create');
    expect($endpoints['query_order'])->toBe('order/query');
    expect($endpoints['query_recharge_account'])->toBe('rechargeaccount/query');
});

it('has correct palmpay network map', function () {
    $map = config('palmpay.network_map');

    expect($map)->toHaveKeys(['1', '2', '3', '4']);
    expect($map['1'])->toBe('MTN');
    expect($map['2'])->toBe('GLO');
    expect($map['3'])->toBe('9MOBILE');
    expect($map['4'])->toBe('AIRTEL');
});

// --- PalmpayTransaction Model Tests ---

it('can create a palmpay transaction', function () {
    $user = User::factory()->create();

    $transaction = PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::AIRTIME,
        'transaction_ref' => 'PP-TEST-' . uniqid(),
        'amount' => 500.00,
        'status' => TransactionStatus::PENDING,
        'request_payload' => ['test' => true],
    ]);

    expect($transaction)->toBeInstanceOf(PalmpayTransaction::class);
    expect($transaction->service_type)->toBe(PalmpayServiceType::AIRTIME);
    expect($transaction->status)->toBe(TransactionStatus::PENDING);
    expect((float) $transaction->amount)->toBe(500.00);
});

it('casts palmpay transaction attributes correctly', function () {
    $user = User::factory()->create();

    $transaction = PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::DATA,
        'transaction_ref' => 'PP-TEST-' . uniqid(),
        'amount' => 1500.50,
        'status' => TransactionStatus::SUCCESS,
        'request_payload' => ['network' => 'MTN', 'plan' => '2GB'],
        'response_payload' => ['orderId' => 'PP_123', 'status' => 'success'],
    ]);

    $fresh = PalmpayTransaction::find($transaction->id);

    expect($fresh->service_type)->toBeInstanceOf(PalmpayServiceType::class);
    expect($fresh->status)->toBeInstanceOf(TransactionStatus::class);
    expect($fresh->request_payload)->toBeArray();
    expect($fresh->response_payload)->toBeArray();
    expect((float) $fresh->amount)->toBe(1500.50);
});

it('enforces unique transaction_ref on palmpay transactions', function () {
    $user = User::factory()->create();
    $ref = 'PP-UNIQUE-' . uniqid();

    PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::AIRTIME,
        'transaction_ref' => $ref,
        'amount' => 100,
        'status' => TransactionStatus::PENDING,
    ]);

    PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::DATA,
        'transaction_ref' => $ref,
        'amount' => 200,
        'status' => TransactionStatus::PENDING,
    ]);
})->throws(\Illuminate\Database\QueryException::class);

it('scopes palmpay transactions by service type', function () {
    $user = User::factory()->create();

    PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::AIRTIME,
        'transaction_ref' => 'PP-AIR-' . uniqid(),
        'amount' => 100,
        'status' => TransactionStatus::SUCCESS,
    ]);

    PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::DATA,
        'transaction_ref' => 'PP-DAT-' . uniqid(),
        'amount' => 500,
        'status' => TransactionStatus::SUCCESS,
    ]);

    PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::BETTING,
        'transaction_ref' => 'PP-BET-' . uniqid(),
        'amount' => 1000,
        'status' => TransactionStatus::SUCCESS,
    ]);

    $airtime = PalmpayTransaction::byServiceType(PalmpayServiceType::AIRTIME)->get();
    $data = PalmpayTransaction::byServiceType(PalmpayServiceType::DATA)->get();
    $betting = PalmpayTransaction::byServiceType(PalmpayServiceType::BETTING)->get();

    expect($airtime)->toHaveCount(1);
    expect($data)->toHaveCount(1);
    expect($betting)->toHaveCount(1);
});

it('scopes palmpay transactions by status', function () {
    $user = User::factory()->create();

    PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::AIRTIME,
        'transaction_ref' => 'PP-S1-' . uniqid(),
        'amount' => 100,
        'status' => TransactionStatus::SUCCESS,
    ]);

    PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::AIRTIME,
        'transaction_ref' => 'PP-S2-' . uniqid(),
        'amount' => 200,
        'status' => TransactionStatus::FAILED,
    ]);

    PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::DATA,
        'transaction_ref' => 'PP-S3-' . uniqid(),
        'amount' => 300,
        'status' => TransactionStatus::PENDING,
    ]);

    expect(PalmpayTransaction::successful()->count())->toBe(1);
    expect(PalmpayTransaction::failed()->count())->toBe(1);
    expect(PalmpayTransaction::pending()->count())->toBe(1);
});

it('scopes palmpay transactions by user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    PalmpayTransaction::create([
        'user_id' => $user1->sId,
        'service_type' => PalmpayServiceType::AIRTIME,
        'transaction_ref' => 'PP-U1-' . uniqid(),
        'amount' => 100,
        'status' => TransactionStatus::SUCCESS,
    ]);

    PalmpayTransaction::create([
        'user_id' => $user2->sId,
        'service_type' => PalmpayServiceType::DATA,
        'transaction_ref' => 'PP-U2-' . uniqid(),
        'amount' => 500,
        'status' => TransactionStatus::SUCCESS,
    ]);

    expect(PalmpayTransaction::byUser($user1->sId)->count())->toBe(1);
    expect(PalmpayTransaction::byUser($user2->sId)->count())->toBe(1);
});

it('has user relationship on palmpay transaction', function () {
    $user = User::factory()->create();

    $transaction = PalmpayTransaction::create([
        'user_id' => $user->sId,
        'service_type' => PalmpayServiceType::AIRTIME,
        'transaction_ref' => 'PP-REL-' . uniqid(),
        'amount' => 100,
        'status' => TransactionStatus::PENDING,
    ]);

    expect($transaction->user)->toBeInstanceOf(User::class);
    expect($transaction->user->sId)->toBe($user->sId);
});

// --- AirtimeService Network Map Tests ---

it('maps network IDs to palmpay codes correctly', function () {
    expect(\App\Services\Palmpay\AirtimeService::mapNetworkCode('1'))->toBe('MTN');
    expect(\App\Services\Palmpay\AirtimeService::mapNetworkCode('2'))->toBe('GLO');
    expect(\App\Services\Palmpay\AirtimeService::mapNetworkCode('3'))->toBe('9MOBILE');
    expect(\App\Services\Palmpay\AirtimeService::mapNetworkCode('4'))->toBe('AIRTEL');
    expect(\App\Services\Palmpay\AirtimeService::mapNetworkCode('99'))->toBeNull();
});

// --- DataService Service Code Map Tests ---

it('maps network and data type to palmpay service codes', function () {
    expect(\App\Services\Palmpay\DataService::mapServiceCode('1', 'SME'))->toBe('MTN_SME');
    expect(\App\Services\Palmpay\DataService::mapServiceCode('2', 'Gifting'))->toBe('GLO_GIFTING');
    expect(\App\Services\Palmpay\DataService::mapServiceCode('3', 'Corporate'))->toBe('9MOBILE_CORPORATE');
    expect(\App\Services\Palmpay\DataService::mapServiceCode('4'))->toBe('AIRTEL_SME');
    expect(\App\Services\Palmpay\DataService::mapServiceCode('99'))->toBeNull();
});

// --- Exception Tests ---

it('creates PalmpayApiException with correct properties', function () {
    $exception = new \App\Exceptions\PalmpayApiException(
        'Test error',
        'ERR_001',
        ['detail' => 'some data'],
        400
    );

    expect($exception->getMessage())->toBe('Test error');
    expect($exception->getErrorCode())->toBe('ERR_001');
    expect($exception->getErrorData())->toBe(['detail' => 'some data']);
    expect($exception->getCode())->toBe(400);
});

it('creates PalmpayTransactionFailedException with response data', function () {
    $responseData = ['orderId' => 'PP_123', 'status' => 'failed'];
    $exception = new \App\Exceptions\PalmpayTransactionFailedException(
        'Transaction failed',
        $responseData
    );

    expect($exception->getMessage())->toBe('Transaction failed');
    expect($exception->getResponseData())->toBe($responseData);
    expect($exception->getCode())->toBe(422);
});

it('creates PalmpayInvalidCustomerException with error data', function () {
    $errorData = ['message' => 'Customer not found'];
    $exception = new \App\Exceptions\PalmpayInvalidCustomerException(
        'Invalid customer',
        $errorData
    );

    expect($exception->getMessage())->toBe('Invalid customer');
    expect($exception->getErrorData())->toBe($errorData);
    expect($exception->getCode())->toBe(400);
});
