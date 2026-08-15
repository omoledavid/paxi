<?php

namespace App\Jobs;

use App\Enums\TransactionStatus;
use App\Models\VtpassTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVtpassWebhook implements ShouldQueue
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
            Log::info('Processing VTpass webhook', ['payload' => $this->payload]);

            // Real VTpass notifications are wrapped: { "type": "transaction-update", "data": { ... } }
            // Fall back to the flat payload for backward compatibility.
            $data = $this->extractData($this->payload);

            $requestId = $data['requestId'] ?? null;
            $code = $data['code'] ?? null;

            if (! $requestId) {
                Log::warning('VTpass webhook missing requestId', ['payload' => $this->payload]);

                return;
            }

            // content.transactions.status is the actual transaction status
            $content = $data['content'] ?? [];
            $transactions = $content['transactions'] ?? [];
            $status = $transactions['status'] ?? null; // delivered, successful, failed, reversed, pending

            // Find transaction by requestId (fallback: VTpass transactionId)
            $transaction = VtpassTransaction::where('transaction_ref', $requestId)->first();

            if (! $transaction) {
                $vtpassRef = $transactions['transactionId'] ?? null;
                if ($vtpassRef) {
                    $transaction = VtpassTransaction::where('vtpass_ref', $vtpassRef)->first();
                }
            }

            if (! $transaction) {
                Log::warning('VTpass webhook transaction not found', [
                    'request_id' => $requestId,
                    'payload' => $this->payload,
                ]);

                return;
            }

            $newStatus = $this->mapStatus($status, $code);

            if ($newStatus && $transaction->status !== $newStatus) {
                $updateData = [
                    'vtpass_ref' => $transactions['transactionId'] ?? $transaction->vtpass_ref,
                    'response_payload' => array_merge($transaction->response_payload ?? [], ['webhook' => $this->payload]),
                ];

                if ($newStatus === TransactionStatus::FAILED) {
                    $updateData['error_message'] = $data['response_description']
                        ?? 'VTpass transaction '.$status;
                    $updateData['error_code'] = $code;
                }

                $updateData['status'] = $newStatus;

                $transaction->update($updateData);

                Log::info("VTpass webhook updated transaction {$transaction->id} to {$newStatus->value}");
            } else {
                Log::info("VTpass webhook no status update needed for {$transaction->id} (Current: {$transaction->status->value}, Incoming: $status)");
            }
        } catch (\Exception $e) {
            Log::error('Failed to process VTpass webhook', [
                'payload' => $this->payload,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Extract the notification data from the wrapped payload.
     */
    protected function extractData(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return $payload;
    }

    /**
     * Map the VTpass status (and response code) to our transaction status.
     */
    protected function mapStatus(?string $status, ?string $code): ?TransactionStatus
    {
        $statusLower = strtolower((string) $status);

        $mapped = match ($statusLower) {
            'delivered', 'successful', 'success' => TransactionStatus::SUCCESS,
            'failed', 'reversed' => TransactionStatus::FAILED,
            'pending', 'initiated' => TransactionStatus::PENDING,
            default => null,
        };

        // High-level response code "000" indicates success.
        if ($mapped === null && $code === '000') {
            return TransactionStatus::SUCCESS;
        }

        return $mapped;
    }
}
