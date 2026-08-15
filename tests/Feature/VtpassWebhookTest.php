<?php

use App\Enums\TransactionStatus;
use App\Jobs\ProcessVtpassWebhook;
use App\Models\User;
use App\Models\VtpassTransaction;
use Illuminate\Support\Facades\Queue;

function vtpassPayload(array $data): array
{
    return [
        'type' => 'transaction-update',
        'data' => $data,
    ];
}

function makeVtpassTransaction(string $requestId, string $status = 'pending'): VtpassTransaction
{
    $user = User::factory()->create();

    return VtpassTransaction::create([
        'user_id' => $user->sId,
        'service_type' => 'airtime',
        'transaction_ref' => $requestId,
        'amount' => 100,
        'status' => $status,
        'request_payload' => ['requestId' => $requestId],
        'response_payload' => [],
    ]);
}

test('vtpass webhook acknowledges with the expected response', function () {
    Queue::fake();

    $payload = vtpassPayload([
        'code' => '000',
        'requestId' => 'ELAET1RA7PC06250-12S5962-2102P',
        'content' => [
            'transactions' => [
                'status' => 'delivered',
                'transactionId' => '1583519914158857111079',
            ],
        ],
    ]);

    $response = $this->postJson('/webhooks/vtpass', $payload);

    $response->assertStatus(200);
    $response->assertExactJson(['response' => 'success']);

    Queue::assertPushed(ProcessVtpassWebhook::class, function ($job) use ($payload) {
        return $job->payload === $payload;
    });
});

test('vtpass webhook acknowledges even when requestId is missing', function () {
    Queue::fake();

    $response = $this->postJson('/webhooks/vtpass', vtpassPayload(['code' => '000']));

    $response->assertStatus(200);
    $response->assertExactJson(['response' => 'success']);
});

test('vtpass webhook job marks a delivered transaction as success', function () {
    $transaction = makeVtpassTransaction('REQ-DELIVERED');

    $job = new ProcessVtpassWebhook(vtpassPayload([
        'code' => '000',
        'requestId' => 'REQ-DELIVERED',
        'content' => [
            'transactions' => [
                'status' => 'delivered',
                'transactionId' => '1583519914158857111079',
            ],
        ],
        'response_description' => 'TRANSACTION DELIVERED',
    ]));

    $job->handle();

    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::SUCCESS);
    expect($transaction->vtpass_ref)->toBe('1583519914158857111079');
    expect($transaction->response_payload)->toHaveKey('webhook');
});

test('vtpass webhook job marks a reversed transaction as failed', function () {
    $transaction = makeVtpassTransaction('REQ-REVERSED');

    $job = new ProcessVtpassWebhook(vtpassPayload([
        'code' => '040',
        'requestId' => 'REQ-REVERSED',
        'content' => [
            'transactions' => [
                'status' => 'reversed',
                'transactionId' => '1583501216545377109916',
                'wallet_credit_id' => '15835022148401519675278125',
            ],
        ],
        'response_description' => 'TRANSACTION REVERSAL TO WALLET',
    ]));

    $job->handle();

    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::FAILED);
    expect($transaction->error_code)->toBe('040');
    expect($transaction->error_message)->toBe('TRANSACTION REVERSAL TO WALLET');
});

test('vtpass webhook job handles legacy flat payload', function () {
    $transaction = makeVtpassTransaction('REQ-FLAT');

    $job = new ProcessVtpassWebhook([
        'code' => '000',
        'requestId' => 'REQ-FLAT',
        'content' => [
            'transactions' => [
                'status' => 'successful',
                'transactionId' => '1583519914158857111079',
            ],
        ],
    ]);

    $job->handle();

    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::SUCCESS);
});

test('vtpass webhook job does nothing when the transaction is unknown', function () {
    Queue::fake();

    $job = new ProcessVtpassWebhook(vtpassPayload([
        'code' => '000',
        'requestId' => 'REQ-UNKNOWN',
        'content' => [
            'transactions' => ['status' => 'delivered'],
        ],
    ]));

    $job->handle();

    $this->assertDatabaseMissing('vtpass_transactions', [
        'transaction_ref' => 'REQ-UNKNOWN',
    ]);
});
