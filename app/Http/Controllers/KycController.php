<?php

namespace App\Http\Controllers;

use App\Http\Requests\Kyc\InitiateKycRequest;
use App\Models\KycAttempt;
use App\Services\SmileIdentityKycService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class KycController extends Controller
{
    use ApiResponses;

    protected SmileIdentityKycService $kycService;

    public function __construct(SmileIdentityKycService $kycService)
    {
        $this->kycService = $kycService;
    }

    /**
     * Initiate a new KYC Job
     */
    public function initiate(InitiateKycRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $result = $this->kycService->initiateKyc($user, $request->validated());

            return $this->ok([
                'message' => 'KYC Job Initiated',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('KYC Initiation Failed: '.$e->getMessage());

            return $this->error([
                'message' => 'Failed to initiate KYC',
                'errors' => ['error' => $e->getMessage()], // Hide details in prod usually
            ], 500);
        }
    }

    /**
     * Handle Smile Identity Webhook
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        // Validate Signature
        // Note: The prompt says "Validate Smile-Signature header using HMAC SHA256 with callback_secret."
        // We will assume the service handles specific validation or we do it here.
        // Let's do a basic check here if not in service logic detail.
        // Actually, I put a method in Service calling it.

        if (! $this->kycService->validateWebhookSignature($request)) {
            Log::warning('Invalid SmileID Webhook Signature', $request->headers->all());

            return response()->json(['message' => 'Invalid Signature'], 401);
        }

        try {
            $this->kycService->handleWebhook($request->all());
        } catch (\Exception $e) {
            Log::error('KYC Webhook Processing Failed: '.$e->getMessage());
            // Return 200 to acknowledge receipt even on error, to prevent retries if it's a logic error
        }

        return response()->json(['success' => true]);
    }

    /**
     * Check Status of a Job (Polling fallback)
     */
    public function status(string $jobId): JsonResponse
    {
        $attempt = KycAttempt::where('job_id', $jobId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $attempt,
        ]);
    }
}
