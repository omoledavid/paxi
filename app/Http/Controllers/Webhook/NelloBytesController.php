<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessNelloBytesWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NelloBytesController extends Controller
{
    /**
     * Handle NelloBytes webhook callback
     * Accepts both query string and JSON payloads
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            // Get payload from either query string or JSON body
            $payload = $this->extractPayload($request);

            Log::info('NelloBytes webhook received', [
                'payload' => $payload,
                'headers' => $request->headers->all(),
                'query' => $request->query->all(),
            ]);

            // Validate webhook signature if secret is configured
            if (config('nellobytes.webhook_secret')) {
                if (!$this->validateSignature($request, $payload)) {
                    Log::warning('NelloBytes webhook signature validation failed', [
                        'payload' => $payload,
                    ]);

                    return response()->json(['message' => 'Invalid signature'], 401);
                }
            }

            // Queue webhook processing
            ProcessNelloBytesWebhook::dispatch($payload);

            // Return 200 immediately to NelloBytes
            return response()->json(['message' => 'Webhook received'], 200);
        } catch (\Exception $e) {
            Log::error('NelloBytes webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            // Still return 200 to prevent NelloBytes from retrying
            return response()->json(['message' => 'Webhook received'], 200);
        }
    }

    /**
     * Extract payload from request (query string or JSON)
     *
     * @param Request $request
     * @return array
     */
    protected function extractPayload(Request $request): array
    {
        // Try JSON body first
        if ($request->isJson() && $request->getContent()) {
            $json = $request->json()->all();
            if (!empty($json)) {
                return $json;
            }
        }

        // Fall back to query string
        $query = $request->query->all();
        if (!empty($query)) {
            return $query;
        }

        // Fall back to form data
        return $request->all();
    }

    /**
     * Validate webhook signature
     *
     * @param Request $request
     * @param array $payload
     * @return bool
     */
    protected function validateSignature(Request $request, array $payload): bool
    {
        $secret = config('nellobytes.webhook_secret');
        if (!$secret) {
            return true; // No secret configured, skip validation
        }

        $signature = $request->header('X-NelloBytes-Signature') 
            ?? $request->header('NelloBytes-Signature')
            ?? $request->input('signature');

        if (!$signature) {
            return false;
        }

        // Remove signature from payload before computing hash
        // The signature was computed on the payload without the signature itself
        $payloadForHash = $payload;
        unset($payloadForHash['signature']);

        // Create expected signature
        $payloadString = is_array($payloadForHash) ? json_encode($payloadForHash) : (string) $payloadForHash;
        $expectedSignature = hash_hmac('sha256', $payloadString, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}

