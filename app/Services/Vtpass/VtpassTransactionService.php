<?php

namespace App\Services\Vtpass;

use App\Enums\TransactionStatus;
use App\Exceptions\VtpassTransactionFailedException;
use App\Models\VtpassTransaction;
use App\Models\User;

class VtpassTransactionService
{
    /**
     * Handle the response from VTpass API.
     *
     * VTpass success code is '000'.
     *
     * @throws VtpassTransactionFailedException
     */
    public function handleProviderResponse(
        array $response,
        VtpassTransaction $transaction,
        User $user,
        float $amount
    ): array {
        // VTpass success code is '000'
        $code = $response['code'] ?? null;
        $content = $response['content'] ?? [];
        $transactionData = $content['transactions'] ?? [];

        // Successful case: code === '000'
        if ($code === '000') {
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'vtpass_ref' => $transactionData['transactionId'] ?? null,
                'response_payload' => $response,
            ]);

            return $response;
        }

        // Failure case
        // 1. Update transaction to FAILED
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'vtpass_ref' => $transactionData['transactionId'] ?? null,
            'response_payload' => $response,
        ]);

        // 2. Refund the user
        $message = $response['response_description'] ?? $response['message'] ?? 'Transaction failed';

        creditWallet(
            user: $user,
            amount: $amount,
            serviceName: 'Wallet Refund',
            serviceDesc: 'Refund for failed VTpass transaction ' . $transaction->transaction_ref . ': ' . $message,
            transactionRef: null,
            wrapInTransaction: false
        );

        // 3. Throw exception to stop flow
        throw new VtpassTransactionFailedException($message, $response);
    }
}
