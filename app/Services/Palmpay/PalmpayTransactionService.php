<?php

namespace App\Services\Palmpay;

use App\Enums\TransactionStatus;
use App\Exceptions\PalmpayTransactionFailedException;
use App\Models\PalmpayTransaction;
use App\Models\User;

class PalmpayTransactionService
{
    /**
     * PalmPay order status constants (from data dictionary)
     * 0 = unpaid, 1 = paying, 2 = success, 3 = fail, 4 = close
     */
    private const ORDER_STATUS_SUCCESS = 2;
    private const ORDER_STATUS_FAIL = 3;
    private const ORDER_STATUS_CLOSE = 4;

    /**
     * Handle the response from PalmPay API.
     *
     * PalmPay success: respCode "00000000" and status true.
     * Order status: integer (0=unpaid, 1=paying, 2=success, 3=fail, 4=close)
     *
     * @throws PalmpayTransactionFailedException
     */
    public function handleProviderResponse(
        array $response,
        PalmpayTransaction $transaction,
        User $user,
        float $amount
    ): array {
        // If the service wrapped the response, extract the raw PalmPay response
        $palmpayResponse = $response['raw_response'] ?? $response;

        // Check API-level success (respCode)
        $respCode = $palmpayResponse['respCode'] ?? null;
        $data = $palmpayResponse['data'] ?? [];
        $orderStatus = is_array($data) ? ($data['orderStatus'] ?? null) : null;

        // API call succeeded (respCode "00000000")
        // Order may still be processing (status 0 or 1) — treat as success for now
        if ($respCode === '00000000') {
            // If order explicitly failed or closed, handle as failure
            if ($orderStatus === self::ORDER_STATUS_FAIL || $orderStatus === self::ORDER_STATUS_CLOSE) {
                return $this->handleFailure($response, $transaction, $user, $amount, $data);
            }

            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'provider_ref' => $data['orderNo'] ?? null,
                'response_payload' => $response,
            ]);

            return $response;
        }

        // API call failed
        return $this->handleFailure($response, $transaction, $user, $amount, $data);
    }

    /**
     * Handle a failed transaction: update record, refund user, throw exception.
     *
     * @throws PalmpayTransactionFailedException
     */
    private function handleFailure(
        array $response,
        PalmpayTransaction $transaction,
        User $user,
        float $amount,
        array $data
    ): array {
        $palmpayResponse = $response['raw_response'] ?? $response;

        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'provider_ref' => $data['orderNo'] ?? null,
            'response_payload' => $response,
        ]);

        $message = $data['errorMsg']
            ?? $palmpayResponse['respMsg']
            ?? $response['message']
            ?? 'Transaction failed';

        creditWallet(
            user: $user,
            amount: $amount,
            serviceName: 'Wallet Refund',
            serviceDesc: 'Refund for failed transaction ' . $transaction->transaction_ref . ': ' . $message,
            transactionRef: null,
            wrapInTransaction: false
        );

        throw new PalmpayTransactionFailedException($message, $response);
    }
}
