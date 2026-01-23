<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\VtpassTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VtpassController extends Controller
{
    /**
     * Handle VTpass webhook callback
     * 
     * Expected Payload (example):
     * {
     *     "code": "000",
     *     "content": {
     *         "transactions": {
     *             "status": "delivered",
     *             "product_name": "MTN Airtime VTU",
     *             "unique_element": "08012345678",
     *             "unit_price": 100,
     *             "quantity": 1,
     *             "service_verification": null,
     *             "channel": "api",
     *             "commission": 3,
     *             "total_amount": 97,
     *             "discount": null,
     *             "type": "Airtime Recharge",
     *             "email": "email@example.com",
     *             "phone": "08012345678",
     *             "name": null,
     *             "convinience_fee": 0,
     *             "amount": 100,
     *             "platform": "api",
     *             "method": "api",
     *             "transactionId": "1563870632557"
     *         }
     *     },
     *     "requestId": "806806338538",
     *     "amount": 100,
     *     "transaction_date": "2019-07-23 09:30:32",
     *     "purchased_code": ""
     * }
     */
    public function handleWebhook(Request $request)
    {
        try {
            $payload = $request->all();

            Log::info('VTpass Webhook Received', ['payload' => $payload]);

            // Validate basic structure
            if (!isset($payload['requestId'])) {
                Log::warning('VTpass Webhook: Missing requestId', $payload);
                return response()->json(['status' => 'error', 'message' => 'Missing requestId'], 400);
            }

            $requestId = $payload['requestId'];
            $code = $payload['code'] ?? null;

            // content.transactions.status is the actual status
            $content = $payload['content'] ?? [];
            $transactions = $content['transactions'] ?? [];
            $status = $transactions['status'] ?? null; // delivered, successful, failed?

            // Find Transaction
            $transaction = VtpassTransaction::where('transaction_ref', $requestId)->first();

            if (!$transaction) {
                Log::warning("VTpass Webhook: Transaction not found for requestId: $requestId");
                return response()->json(['status' => 'error', 'message' => 'Transaction not found'], 404);
            }

            // Determine Status
            // Docs say: "status": "delivered" or "successful" usually implies success.
            $newStatus = match (strtolower($status)) {
                'delivered', 'successful' => TransactionStatus::SUCCESS,
                'failed' => TransactionStatus::FAILED,
                'pending', 'initiated' => TransactionStatus::PENDING,
                default => null // Don't update if unknown
            };

            // Also check high-level code? "000" is success.
            if ($code === '000' && !$newStatus) {
                $newStatus = TransactionStatus::SUCCESS;
            }

            if ($newStatus && $transaction->status !== $newStatus) {
                $transaction->update([
                    'status' => $newStatus,
                    'vtpass_ref' => $transactions['transactionId'] ?? $transaction->vtpass_ref,
                    'response_payload' => array_merge($transaction->response_payload ?? [], ['webhook' => $payload]),
                    // 'error_message' => ... if failed?
                ]);

                Log::info("VTpass Webhook: Updated transaction $requestId to {$newStatus->value}");
            } else {
                Log::info("VTpass Webhook: No status update needed for $requestId (Current: {$transaction->status->value}, Incoming: $status)");
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('VTpass Webhook Error', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error'], 500);
        }
    }
}
