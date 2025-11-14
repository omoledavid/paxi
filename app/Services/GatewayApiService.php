<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GatewayApiService
{
    protected string $apiToken;
    protected string $sender;
    protected string $baseUrl = 'https://gatewayapi.com/rest';

    public function __construct()
    {
        $this->apiToken = config('services.gatewayapi.token');
        $this->sender = config('services.gatewayapi.sender', 'Paxi');
    }

    /**
     * Send SMS message via GatewayAPI REST API to a single recipient.
     *
     * @param string $phoneNumber Phone number in international format (234XXXXXXXXXX)
     * @param string $message SMS message content
     * @return array Response with success status and message
     */
    public function sendSms(string $phoneNumber, string $message): array
    {
        return $this->sendBulkSms([$phoneNumber], $message);
    }

    /**
     * Send SMS message via GatewayAPI REST API to multiple recipients.
     *
     * @param array<int, string|int> $recipients List of phone numbers
     * @param string $message SMS message content
     * @return array Response with success status and message
     */
    public function sendBulkSms(array $recipients, string $message): array
    {
        try {
            $formattedRecipients = [];

            foreach ($recipients as $recipient) {
                $formatted = $this->formatPhoneNumber((string) $recipient);

                if (!empty($formatted)) {
                    $formattedRecipients[] = ['msisdn' => $formatted];
                }
            }

            if (empty($formattedRecipients)) {
                return [
                    'success' => false,
                    'message' => 'No valid recipients provided',
                ];
            }

            // Prepare the payload according to GatewayAPI REST API specification
            $payload = [
                'sender' => $this->sender,
                'message' => $message,
                'recipients' => $formattedRecipients,
            ];

            // Send request to GatewayAPI
            $response = Http::withBasicAuth($this->apiToken, '')
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl . '/mtsms', $payload);

            // Check if request was successful
            if ($response->successful()) {
                $data = $response->json();

                Log::info('SMS sent successfully via GatewayAPI', [
                    'recipients' => $formattedRecipients,
                    'message_ids' => $data['ids'] ?? []
                ]);

                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'data' => $data
                ];
            }

            // Handle non-successful responses
            $errorData = $response->json();
            Log::error('GatewayAPI SMS failed', [
                'recipients' => $formattedRecipients,
                'status' => $response->status(),
                'error' => $errorData
            ]);

            return [
                'success' => false,
                'message' => $errorData['message'] ?? 'Failed to send SMS',
                'error' => $errorData
            ];

        } catch (\Exception $e) {
            Log::error('GatewayAPI SMS exception', [
                'recipients' => $recipients,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while sending SMS',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Normalize a phone number into the expected msisdn format.
     */
    protected function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove all non-numeric characters
        $phoneNumber = preg_replace('/\D+/', '', $phoneNumber);

        if (empty($phoneNumber)) {
            return '';
        }

        // Convert Nigerian format from leading 0 to 234
        if (str_starts_with($phoneNumber, '0') && strlen($phoneNumber) === 11) {
            $phoneNumber = '234' . substr($phoneNumber, 1);
        }

        // Remove leading plus sign if still present
        return ltrim($phoneNumber, '+');
    }

    /**
     * Send verification code SMS
     *
     * @param string $phoneNumber Phone number in 0XXXXXXXXXX format
     * @param string $code Verification code
     * @return array Response with success status
     */
    public function sendVerificationCode(string $phoneNumber, string $code): array
    {
        // Convert Nigerian phone format (0XXXXXXXXXX) to international format (234XXXXXXXXXX)
        if (str_starts_with($phoneNumber, '0')) {
            $phoneNumber = '234' . substr($phoneNumber, 1);
        }

        $message = "Your verification code is: {$code}. Valid for 5 minutes. Do not share this code.";

        return $this->sendSms($phoneNumber, $message);
    }

    /**
     * Check API balance (optional utility method)
     *
     * @return array Response with balance information
     */
    public function checkBalance(): array
    {
        try {
            $response = Http::withBasicAuth($this->apiToken, '')
                ->get($this->baseUrl . '/me');

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to retrieve balance'
            ];
        } catch (\Exception $e) {
            Log::error('GatewayAPI balance check failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Error checking balance'
            ];
        }
    }
}

