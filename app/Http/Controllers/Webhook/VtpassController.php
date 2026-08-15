<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVtpassWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VtpassController extends Controller
{
    /**
     * Handle VTpass webhook callback
     *
     * VTpass expects a prompt acknowledgement of {"response": "success"}
     * before any heavy processing, so we dispatch the work to a queued job
     * and acknowledge immediately. Processing happens in ProcessVtpassWebhook.
     *
     * Expected Payload (example):
     * {
     *     "type": "transaction-update",
     *     "data": {
     *         "code": "000",
     *         "content": {
     *             "transactions": {
     *                 "status": "delivered",
     *                 "product_name": "MTN Airtime VTU",
     *                 "unique_element": "08012345678",
     *                 "unit_price": 100,
     *                 "quantity": 1,
     *                 "type": "Airtime Recharge",
     *                 "amount": 100,
     *                 "transactionId": "1563870632557"
     *             }
     *         },
     *         "response_description": "TRANSACTION DELIVERED",
     *         "requestId": "806806338538",
     *         "amount": 100,
     *         "transaction_date": "2019-07-23 09:30:32",
     *         "purchased_code": ""
     *     }
     * }
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            // Get payload from either JSON body, query string or form data
            $payload = $this->extractPayload($request);

            Log::info('VTpass Webhook Received', ['payload' => $payload]);

            // Queue webhook processing
            ProcessVtpassWebhook::dispatch($payload);
        } catch (\Exception $e) {
            Log::error('VTpass Webhook Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
        }

        // Always acknowledge promptly to prevent VTpass retries
        return response()->json(['response' => 'success'], 200);
    }

    /**
     * Extract payload from request (JSON body, query string or form data)
     */
    protected function extractPayload(Request $request): array
    {
        // Try JSON body first
        if ($request->isJson() && $request->getContent()) {
            $json = $request->json()->all();
            if (! empty($json)) {
                return $json;
            }
        }

        // Fall back to query string
        $query = $request->query->all();
        if (! empty($query)) {
            return $query;
        }

        // Fall back to form data
        return $request->all();
    }
}
