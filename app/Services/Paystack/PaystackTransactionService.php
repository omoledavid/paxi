<?php

namespace App\Services\Paystack;

use App\Enums\TransactionStatus;
use App\Exceptions\PaystackTransactionFailedException;
use App\Models\PaystackTransaction;
use App\Models\User;

class PaystackTransactionService
{
    /**
     * Handle the response from Paystack.
     *
     * @throws PaystackTransactionFailedException
     */
    public function handleProviderResponse(
        array $response,
        PaystackTransaction $transaction,
        User $user,
        float $amount
    ): array {
        // Paystack success status is boolean true in 'status' key
        $status = $response['status'] ?? false;

        if ($status === true && ($response['data']['status'] ?? '') === 'success') {
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'paystack_ref' => $response['data']['reference'] ?? null,
                'response_payload' => $response,
            ]);

            return $response;
        }

        // Also handle case where we just get 'status' => true which means request accepted,
        // but maybe we need to check deeper data status?
        // Paystack standard response: { status: true, message: "...", data: { status: "success", ... } }
        // for charge/verify endpoints.
        if ($status === true && ! isset($response['data']['status'])) {
            // For some endpoints getting status: true is enough?
            // Let's assume yes if no specific inner data status contradicts
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'paystack_ref' => $response['data']['reference'] ?? null,
                'response_payload' => $response,
            ]);

            return $response;
        }

        // Failure case
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'paystack_ref' => $response['data']['reference'] ?? null,
            'response_payload' => $response,
        ]);

        // Refund the user
        // Assuming creditWallet helper function exists globally as seen in NelloBytes implementation
        if (function_exists('creditWallet')) {
            creditWallet(
                user: $user,
                amount: $amount,
                serviceName: 'Wallet Refund',
                serviceDesc: 'Refund for failed Paystack transaction '.$transaction->transaction_ref.': '.($response['message'] ?? 'Unknown error'),
                transactionRef: null,
                wrapInTransaction: false
            );
        }

        throw new PaystackTransactionFailedException(
            $response['message'] ?? 'Transaction failed',
            $response
        );
    }
}
