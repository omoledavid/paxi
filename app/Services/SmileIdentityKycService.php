<?php

namespace App\Services;

use App\Models\KycAttempt;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;
use SmileIdentity\Signature;
use SmileIdentity\SmileIdentityCore;

class SmileIdentityKycService
{
    protected string $partnerId;

    protected string $defaultCallback;

    protected string $apiKey;

    protected string $sidServerId; // 0 for sandbox, 1 for production

    public function __construct()
    {
        $partnerId = config('services.smile_identity.partner_id');
        $apiKey = config('services.smile_identity.api_key');

        if (empty($partnerId) || empty($apiKey)) {
            throw new Exception('Smile Identity configuration is missing. Please check your .env file for SMILE_IDENTITY_PARTNER_ID and SMILE_IDENTITY_API_KEY.');
        }

        $this->partnerId = $partnerId;
        $this->apiKey = $apiKey;
        $this->defaultCallback = config('services.smile_identity.callback_url') ?? '';
        $this->sidServerId = config('services.smile_identity.env') === 'production' ? '1' : '0';
    }

    /**
     * Initiate a SmartSelfie Verification Job
     */
    public function initiateKyc(User $user, array $data = []): array
    {
        $jobId = 'job_' . uniqid() . '_' . $user->sId;
        $userId = (string) $user->sId;

        // Create a pending attempt record
        $attempt = $user->kycAttempts()->create([
            'job_id' => $jobId,
            'status' => 'pending',
            'product_type' => $data['product_type'] ?? 'biometric_kyc',
            'template_id' => $data['template_id'] ?? 10,
            'nin' => $data['nin'] ?? null,
        ]);

        try {
            // Instantiate SDK Core
            $core = new SmileIdentityCore(
                $this->partnerId,
                $this->defaultCallback,
                $this->apiKey,
                $this->sidServerId
            );

            $idInfo = null;
            if (!empty($data['nin'])) {
                $idInfo = [
                    'country' => 'NG',
                    'id_type' => 'NIN_V2',
                    'id_number' => $data['nin'],
                ];
            }

            // Generate Web Token
            $response = $core->get_web_token(
                $userId,
                $jobId,
                $attempt->product_type,
                null,
                $this->defaultCallback
            );

            return [
                'job_id' => $jobId,
                'user_id' => $userId,
                'token' => $response['signature'] ?? null,
                'timestamp' => $response['timestamp'] ?? null,
                'partner_id' => $this->partnerId,
                'callback_url' => $this->defaultCallback,
                'product_type' => $attempt->product_type,
                'signature' => $response['signature'] ?? null,
                'id_info' => $idInfo,
                'full_response' => $response,
            ];

        } catch (Exception $e) {
            $attempt->update(['status' => 'failed', 'rejection_reason' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Handle Webhook Callback
     */
    public function handleWebhook(array $payload): void
    {
        $smileJobId = $payload['SmileJobID'] ?? null;
        $internalJobId = $payload['PartnerParams']['job_id'] ?? null;

        if (!$internalJobId) {
            Log::error('SmileID Webhook missing internal JobID (PartnerParams.job_id)', $payload);

            return;
        }

        $attempt = KycAttempt::where('job_id', $internalJobId)->first();
        if (!$attempt) {
            Log::warning("SmileID Webhook Job Not Found: $internalJobId");

            return;
        }

        $resultCode = $payload['ResultCode'] ?? null;
        $resultText = $payload['ResultText'] ?? '';

        // 0810 is standard success
        $isApproved = $resultCode == '0810';

        // NIN Match check (NIN V2 specific)
        $actions = $payload['Actions'] ?? [];
        $ninMatch = isset($actions['Verify_ID_Number']) && $actions['Verify_ID_Number'] === 'Completed';

        if ($isApproved) {
            $attempt->status = 'approved';
            $attempt->face_match = true;
            if ($ninMatch) {
                $attempt->nin_match = true;
            }
        } else {
            $attempt->status = 'rejected';
            $attempt->rejection_reason = $resultText;
        }

        $attempt->result_json = $payload;
        $attempt->confidence_value = $payload['ConfidenceValue'] ?? 0;
        $attempt->save();

        // Update User
        $user = $attempt->user;
        if ($user) {
            if ($attempt->status === 'approved') {
                $user->kyc_status = 'approved';
                $user->kyc_approved_at = now();
                $user->kyc_job_id = $smileJobId;
                if ($ninMatch) {
                    $user->nin_verified = '1';
                }
                $user->save();

                event(new \App\Events\KycApproved($user, $attempt));
            } else {
                $user->kyc_status = 'rejected';
                $user->save();
                event(new \App\Events\KycRejected($user, $attempt));
            }
        }
    }

    /**
     * Validate Webhook Signature
     */
    public function validateWebhookSignature(\Illuminate\Http\Request $request): bool
    {
        $signature = $request->header('SmileID-Signature');
        $timestamp = $request->header('SmileID-Timestamp');

        if (!$signature || !$timestamp) {
            return false;
        }

        // Use configured callback secret if provided, otherwise assume API key
        $secretKey = config('services.smile_identity.callback_secret') ?? $this->apiKey;

        // Manual validation to ensure we use the secret key
        $message = $timestamp . $this->partnerId . 'sid_request';
        $expectedSignature = base64_encode(hash_hmac('sha256', $message, $secretKey, true));

        return hash_equals($expectedSignature, $signature);
    }
}
