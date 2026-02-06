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

        // Successful case: 
        // 1. For EPIN transactions: Check for TXN_EPIN array (no status field in response)
        // 2. For other transactions: Check for 'ORDER_RECEIVED' or 'success' status
        $isEpinSuccess = isset($response['TXN_EPIN']) && is_array($response['TXN_EPIN']) && count($response['TXN_EPIN']) > 0;
        $isStatusSuccess = $status === 'ORDER_RECEIVED' || $status === 'success';

        if ($isEpinSuccess || $isStatusSuccess) {
            // Extract nellobytes reference
            $nellobytesRef = null;
            if ($isEpinSuccess) {
                // For EPIN transactions, extract from TXN_EPIN array
                $txnPins = $response['TXN_EPIN'];
                $primaryTxn = is_array($txnPins) && count($txnPins) ? $txnPins[0] : [];
                $nellobytesRef = $primaryTxn['transactionid'] ?? null;
            } else {
                // For other transactions, extract from standard fields
                $nellobytesRef = $response['reference'] ?? $response['ref'] ?? $response['orderid'] ?? null;
            }

            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
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
            serviceDesc: 'Refund for failed transaction ' . $transaction->transaction_ref . ': ' . ($response['message'] ?? 'Unknown error'),
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
