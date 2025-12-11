<?php

namespace App\Jobs;

use App\Enums\TransactionStatus;
use App\Events\NelloBytesTransactionUpdated;
use App\Models\NelloBytesTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessNelloBytesWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $payload;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Processing NelloBytes webhook', ['payload' => $this->payload]);

            // Extract transaction reference from payload
            $transactionRef = $this->extractTransactionRef($this->payload);
            $nellobytesRef = $this->extractNelloBytesRef($this->payload);
            $status = $this->extractStatus($this->payload);

            if (!$transactionRef && !$nellobytesRef) {
                Log::warning('NelloBytes webhook missing transaction reference', [
                    'payload' => $this->payload,
                ]);
                return;
            }

            // Find transaction by reference
            $transaction = null;
            if ($transactionRef) {
                $transaction = NelloBytesTransaction::where('transaction_ref', $transactionRef)->first();
            }

            if (!$transaction && $nellobytesRef) {
                $transaction = NelloBytesTransaction::where('nellobytes_ref', $nellobytesRef)->first();
            }

            if (!$transaction) {
                Log::warning('NelloBytes webhook transaction not found', [
                    'transaction_ref' => $transactionRef,
                    'nellobytes_ref' => $nellobytesRef,
                    'payload' => $this->payload,
                ]);
                return;
            }

            // Update transaction status
            $updateData = [
                'response_payload' => $this->payload,
            ];

            if ($status) {
                $updateData['status'] = $status;
            }

            if ($nellobytesRef && !$transaction->nellobytes_ref) {
                $updateData['nellobytes_ref'] = $nellobytesRef;
            }

            // Extract error information if status is failed
            if ($status === TransactionStatus::FAILED) {
                $updateData['error_message'] = $this->extractErrorMessage($this->payload);
                $updateData['error_code'] = $this->extractErrorCode($this->payload);
            }

            $transaction->update($updateData);

            // Fire event
            event(new NelloBytesTransactionUpdated($transaction, $this->payload));

            Log::info('NelloBytes webhook processed successfully', [
                'transaction_id' => $transaction->id,
                'transaction_ref' => $transaction->transaction_ref,
                'status' => $transaction->status->value,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process NelloBytes webhook', [
                'payload' => $this->payload,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Extract transaction reference from payload
     *
     * @param array $payload
     * @return string|null
     */
    protected function extractTransactionRef(array $payload): ?string
    {
        return $payload['reference']
            ?? $payload['ref']
            ?? $payload['transaction_ref']
            ?? $payload['TransactionRef']
            ?? $payload['requestid']
            ?? $payload['RequestID']
            ?? $payload['request_id']
            ?? null;
    }

    /**
     * Extract NelloBytes reference from payload
     *
     * @param array $payload
     * @return string|null
     */
    protected function extractNelloBytesRef(array $payload): ?string
    {
        return $payload['nellobytes_ref']
            ?? $payload['NelloBytesRef']
            ?? $payload['order_id']
            ?? $payload['orderid']
            ?? $payload['OrderID']
            ?? null;
    }

    /**
     * Extract status from payload
     *
     * @param array $payload
     * @return TransactionStatus|null
     */
    protected function extractStatus(array $payload): ?TransactionStatus
    {
        $status = $payload['status'] ?? $payload['Status'] ?? null;

        // Prefer explicit order status / status code mappings for Smile callbacks
        $statusCode = $payload['statuscode'] ?? $payload['status_code'] ?? null;
        $orderStatus = $payload['orderstatus'] ?? $payload['order_status'] ?? null;

        if ($statusCode !== null) {
            $codeInt = is_numeric($statusCode) ? (int) $statusCode : null;
            if ($codeInt === 200) {
                return TransactionStatus::SUCCESS;
            }
            if ($codeInt === 100) {
                return TransactionStatus::PENDING;
            }
            if (in_array($codeInt, [400, 500], true)) {
                return TransactionStatus::FAILED;
            }
        }

        if ($orderStatus) {
            $normalized = strtolower($orderStatus);
            return match ($normalized) {
                'order_completed', 'completed', 'success', 'successful' => TransactionStatus::SUCCESS,
                'order_received', 'order_onhold', 'pending' => TransactionStatus::PENDING,
                'order_cancelled', 'order_canceled', 'cancelled', 'canceled' => TransactionStatus::CANCELLED,
                default => null,
            };
        }

        if (!$status) {
            return null;
        }

        $statusLower = strtolower($status);

        return match ($statusLower) {
            'success', 'successful', 'completed' => TransactionStatus::SUCCESS,
            'failed', 'failure', 'error' => TransactionStatus::FAILED,
            'cancelled', 'canceled' => TransactionStatus::CANCELLED,
            'pending', 'processing' => TransactionStatus::PENDING,
            default => null,
        };
    }

    /**
     * Extract error message from payload
     *
     * @param array $payload
     * @return string|null
     */
    protected function extractErrorMessage(array $payload): ?string
    {
        return $payload['message']
            ?? $payload['msg']
            ?? $payload['error']
            ?? $payload['error_message']
            ?? $payload['orderremark']
            ?? null;
    }

    /**
     * Extract error code from payload
     *
     * @param array $payload
     * @return string|null
     */
    protected function extractErrorCode(array $payload): ?string
    {
        return $payload['code']
            ?? $payload['error_code']
            ?? $payload['ErrorCode']
            ?? $payload['statuscode']
            ?? null;
    }
}

