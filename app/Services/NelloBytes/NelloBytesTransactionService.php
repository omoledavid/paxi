<?php

namespace App\Services\NelloBytes;

use App\Enums\TransactionStatus;
use App\Exceptions\NelloBytesTransactionFailedException;
use App\Models\NelloBytesTransaction;
use App\Models\User;

class NelloBytesTransactionService
{
    /**
     * Handle the response from the provider (NelloBytes).
     *
     * @throws NelloBytesTransactionFailedException
     */
    public function handleProviderResponse(
        array $response,
        NelloBytesTransaction $transaction,
        User $user,
        float $amount
    ): array {
        // Extract status and message
        $status = $response['status'] ?? null;

        // Successful case: typically 'ORDER_RECEIVED' or 'success'
        // Adjust this check based on actual API success indicators
        if ($status === 'ORDER_RECEIVED' || $status === 'success') {
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $response['reference'] ?? $response['ref'] ?? null,
                'response_payload' => $response,
            ]);

            return $response;
        }

        // Failure case
        // 1. Update transaction to FAILED
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'nellobytes_ref' => $response['reference'] ?? $response['ref'] ?? null,
            'response_payload' => $response,
        ]);

        // 2. Refund the user
        creditWallet(
            user: $user,
            amount: $amount,
            serviceName: 'Wallet Refund',
            serviceDesc: 'Refund for failed transaction '.$transaction->transaction_ref.': '.($response['message'] ?? 'Unknown error'),
            transactionRef: null, // Generate new ref for the refund transaction
            wrapInTransaction: false // Already inside a transaction in the controller typically
        );

        // 3. Throw exception to stop flow and return error to user
        // We use a custom exception so we can catch it specifically in the controller
        // and commit the transaction (saving the 'FAILED' status and Refund)
        throw new NelloBytesTransactionFailedException(
            $response['message'] ?? $response['msg'] ?? 'Transaction failed',
            $response
        );
    }
}
