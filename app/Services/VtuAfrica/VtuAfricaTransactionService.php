<?php

namespace App\Services\VtuAfrica;

use App\Enums\TransactionStatus;
use App\Exceptions\VtuAfricaTransactionFailedException;
use App\Models\VtuAfricaTransaction;
use App\Models\User;

class VtuAfricaTransactionService
{
    /**
     * Handle the response from VTU Africa API.
     *
     * VTU Africa success code is 101.
     *
     * @throws VtuAfricaTransactionFailedException
     */
    public function handleProviderResponse(
        array $response,
        VtuAfricaTransaction $transaction,
        User $user,
        float $amount
    ): array {
        // VTU Africa success code is 101
        $code = $response['code'] ?? null;
        $description = $response['description'] ?? [];
        $status = is_array($description) ? ($description['Status'] ?? null) : null;

        // Successful case: code 101 with Status 'Completed' or 'Processing'
        // VTU Africa returns 'Processing' for successful funding requests
        $successStatuses = ['Completed', 'Processing'];
        if ($code == 101) {
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'provider_ref' => $description['ReferenceID'] ?? null,
                'response_payload' => $response,
            ]);

            return $response;
        }

        // Failure case
        // 1. Update transaction to FAILED
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'provider_ref' => $description['ReferenceID'] ?? null,
            'response_payload' => $response,
        ]);

        // 2. Refund the user
        $message = is_array($description)
            ? ($description['message'] ?? 'Transaction failed')
            : ($description ?: 'Transaction failed');

        creditWallet(
            user: $user,
            amount: $amount,
            serviceName: 'Wallet Refund',
            serviceDesc: 'Refund for failed transaction ' . $transaction->transaction_ref . ': ' . $message,
            transactionRef: null,
            wrapInTransaction: false
        );

        // 3. Throw exception to stop flow
        throw new VtuAfricaTransactionFailedException($message, $response);
    }
}
