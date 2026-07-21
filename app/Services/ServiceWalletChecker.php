<?php

namespace App\Services;

use App\Models\ApiConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ServiceWalletChecker
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('services.wallet_check', []);
    }

    /**
     * Check if a service has sufficient balance for a transaction.
     *
     * @param string $service Service name (nellobytes, vtpass, vtuafrica, palmpay, paystack, smileid, gatewayapi, one, two, three)
     * @param float $transactionAmount The amount needed for the transaction
     * @return array ['status' => 'success|fail', 'balance' => float, 'has_sufficient' => bool, 'message' => string]
     */
    public function checkBalance(string $service, float $transactionAmount): array
    {
        $service = strtolower($service);

        // Check if service is enabled
        if (!$this->isServiceEnabled($service)) {
            return [
                'status' => 'fail',
                'balance' => 0,
                'has_sufficient' => false,
                'message' => "Service '{$service}' is not enabled for wallet checks.",
            ];
        }

        // Get the appropriate check method
        $method = 'check' . ucfirst($service) . 'Balance';
        if (!method_exists($this, $method)) {
            return [
                'status' => 'fail',
                'balance' => 0,
                'has_sufficient' => false,
                'message' => "No wallet check method found for service: {$service}",
            ];
        }

        // Call the specific service check method
        $balanceResult = $this->$method();

        if ($balanceResult['status'] !== 'success') {
            return [
                'status' => 'fail',
                'balance' => $balanceResult['balance'] ?? 0,
                'has_sufficient' => false,
                'message' => $balanceResult['message'] ?? "Failed to check {$service} wallet balance.",
            ];
        }

        $serviceBalance = (float) $balanceResult['balance'];
        $requiredAmount = $this->calculateRequiredAmount($transactionAmount);
        $hasSufficient = $serviceBalance >= $requiredAmount;

        return [
            'status' => 'success',
            'balance' => $serviceBalance,
            'required_amount' => $requiredAmount,
            'has_sufficient' => $hasSufficient,
            'message' => $hasSufficient
                ? "Sufficient balance available (₦{$serviceBalance} >= ₦{$requiredAmount})"
                : "Insufficient balance (₦{$serviceBalance} < ₦{$requiredAmount}). Service unavailable at the moment.",
        ];
    }

    /**
     * Quick check method that returns true/false for sufficient balance.
     *
     * @param string $service Service name
     * @param float $transactionAmount The amount needed
     * @return bool True if service has sufficient balance
     */
    public function hasSufficientBalance(string $service, float $transactionAmount): bool
    {
        $result = $this->checkBalance($service, $transactionAmount);
        return $result['status'] === 'success' && $result['has_sufficient'];
    }

    /**
     * Check if a service is enabled for wallet checks.
     */
    protected function isServiceEnabled(string $service): bool
    {
        $enabledServices = $this->config['enabled_services'] ?? [];
        $serviceConfig = $this->config['services'][$service] ?? [];

        return in_array($service, $enabledServices) && ($serviceConfig['enabled'] ?? true);
    }

    /**
     * Calculate the required amount including buffer.
     */
    protected function calculateRequiredAmount(float $transactionAmount): float
    {
        $bufferType = $this->config['use_buffer'] ?? 'amount';
        $bufferAmount = (float) ($this->config['buffer_amount'] ?? 500);
        $bufferPercentage = (float) ($this->config['buffer_percentage'] ?? 5);

        $amountBuffer = $transactionAmount + $bufferAmount;
        $percentageBuffer = $transactionAmount * (1 + $bufferPercentage / 100);

        return match ($bufferType) {
            'percentage' => $percentageBuffer,
            'both' => max($amountBuffer, $percentageBuffer),
            default => $amountBuffer, // 'amount'
        };
    }

    /**
     * Check NelloBytes wallet balance.
     */
    protected function checkNellobytesBalance(): array
    {
        try {
            $apiKey = $this->getApiConfig('nellobytesApi');
            $userID = $this->getApiConfig('nellobytesUserId');

            if (empty($apiKey) || empty($userID)) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'NelloBytes API credentials not configured.',
                ];
            }

            $url = "https://www.nellobytesystems.com/APIWalletBalanceV1.asp?UserID={$userID}&APIKey={$apiKey}";

            $response = Http::timeout(30)
                ->withOptions(['verify' => false])
                ->get($url);

            if (!$response->successful()) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'Failed to connect to NelloBytes API.',
                ];
            }

            $result = $response->json();

            if (isset($result['balance'])) {
                $balance = is_string($result['balance'])
                    ? (float) str_replace(',', '', $result['balance'])
                    : (float) $result['balance'];

                return [
                    'status' => 'success',
                    'balance' => round($balance, 2),
                    'message' => 'Balance retrieved successfully.',
                ];
            }

            if (isset($result['wallet_balance'])) {
                $balance = is_string($result['wallet_balance'])
                    ? (float) str_replace(',', '', $result['wallet_balance'])
                    : (float) $result['wallet_balance'];

                return [
                    'status' => 'success',
                    'balance' => round($balance, 2),
                    'message' => 'Balance retrieved successfully.',
                ];
            }

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Invalid response from NelloBytes API.',
            ];
        } catch (\Exception $e) {
            Log::error('NelloBytes wallet check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Error checking NelloBytes wallet: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check VTpass wallet balance.
     */
    protected function checkVtpassBalance(): array
    {
        try {
            $apiKey = $this->getApiConfig('vtApiKey');
            $publicKey = $this->getApiConfig('vtPublicKey');

            if (empty($apiKey) || empty($publicKey)) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'VTpass API credentials not configured.',
                ];
            }

            $response = Http::timeout(30)
                ->withHeaders([
                    'api-key' => $apiKey,
                    'public-key' => $publicKey,
                ])
                ->get('https://vtpass.com/api/balance');

            if (!$response->successful()) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'Failed to connect to VTpass API.',
                ];
            }

            $result = $response->json();

            if (isset($result['contents']['balance'])) {
                return [
                    'status' => 'success',
                    'balance' => (float) $result['contents']['balance'],
                    'message' => 'Balance retrieved successfully.',
                ];
            }

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Invalid response from VTpass API.',
            ];
        } catch (\Exception $e) {
            Log::error('VTpass wallet check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Error checking VTpass wallet: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check VTU Africa wallet balance.
     */
    protected function checkVtuafricaBalance(): array
    {
        try {
            $apiKey = $this->getApiConfig('vtuAfricaApiKey');

            if (empty($apiKey)) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'VTU Africa API key not configured.',
                ];
            }

            $response = Http::timeout(30)
                ->withOptions([
                    'verify' => false,
                    'follow_redirects' => true,
                ])
                ->get('https://vtuafrica.com.ng/portal/api/balance/?apikey=' . $apiKey);

            if (!$response->successful()) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'Failed to connect to VTU Africa API.',
                ];
            }

            $result = $response->body();
            $cleanResult = trim($result);

            // Response is a plain number
            if (is_numeric($cleanResult)) {
                return [
                    'status' => 'success',
                    'balance' => round((float) $cleanResult, 2),
                    'message' => 'Balance retrieved successfully.',
                ];
            }

            // Try JSON parsing as fallback
            $jsonResult = json_decode($result, true);
            if (isset($jsonResult['balance'])) {
                return [
                    'status' => 'success',
                    'balance' => round((float) $jsonResult['balance'], 2),
                    'message' => 'Balance retrieved successfully.',
                ];
            }

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Invalid response from VTU Africa API.',
            ];
        } catch (\Exception $e) {
            Log::error('VTU Africa wallet check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Error checking VTU Africa wallet: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check Palmpay wallet balance (if available via API).
     */
    protected function checkPalmpayBalance(): array
    {
        // Palmpay doesn't have a direct wallet balance endpoint in the current integration
        // Return success to allow transactions (balance check will be handled during purchase)
        return [
            'status' => 'success',
            'balance' => PHP_FLOAT_MAX, // Treat as unlimited
            'message' => 'Palmpay balance check skipped (no wallet endpoint available).',
        ];
    }

    /**
     * Check Paystack balance (if available via API).
     */
    protected function checkPaystackBalance(): array
    {
        // Paystack doesn't expose a direct wallet balance endpoint
        // Return success to allow transactions
        return [
            'status' => 'success',
            'balance' => PHP_FLOAT_MAX, // Treat as unlimited
            'message' => 'Paystack balance check skipped (no wallet endpoint available).',
        ];
    }

    /**
     * Check Smile ID wallet balance.
     */
    protected function checkSmileidBalance(): array
    {
        try {
            $partnerId = $this->getApiConfig('smilePartnerId');
            $apiKey = $this->getApiConfig('smileApiKey');
            $env = $this->getApiConfig('smileEnv');

            if (empty($partnerId) || empty($apiKey)) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'Smile ID credentials not configured.',
                ];
            }

            $url = ($env === 'production')
                ? 'https://prod.smileidentity.com/api/v2/partner/wallet_balance'
                : 'https://portal.smileidentity.com/api/v2/partner/wallet_balance';

            $timestamp = gmdate("Y-m-d\TH:i:s.v\Z");
            $message = $timestamp . $partnerId . "sid_request";
            $signature = base64_encode(hash_hmac('sha256', $message, $apiKey, true));

            $requestBody = [
                "currency" => "NGN",
                "environment" => ($env === 'production') ? "production" : "test",
                "partner_id" => $partnerId,
                "signature" => $signature,
                "timestamp" => $timestamp,
            ];

            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions(['verify' => false, 'follow_redirects' => true])
                ->post($url, $requestBody);

            if (!$response->successful()) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'Failed to connect to Smile ID API.',
                ];
            }

            $result = $response->json();

            if (isset($result['wallet_balance'])) {
                return [
                    'status' => 'success',
                    'balance' => round((float) $result['wallet_balance'], 2),
                    'message' => 'Balance retrieved successfully.',
                ];
            }

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Invalid response from Smile ID API.',
            ];
        } catch (\Exception $e) {
            Log::error('Smile ID wallet check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Error checking Smile ID wallet: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check GatewayAPI wallet balance.
     */
    protected function checkGatewayapiBalance(): array
    {
        try {
            $apiToken = $this->getApiConfig('gatewayApiToken');

            if (empty($apiToken)) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'GatewayAPI token not configured.',
                ];
            }

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode($apiToken . ':'),
                ])
                ->withOptions(['verify' => false])
                ->get('https://gatewayapi.com/rest/me');

            if (!$response->successful()) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => 'Failed to connect to GatewayAPI.',
                ];
            }

            $result = $response->json();

            if (isset($result['credit'])) {
                return [
                    'status' => 'success',
                    'balance' => round((float) $result['credit'], 2),
                    'message' => 'Balance retrieved successfully.',
                ];
            }

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Invalid response from GatewayAPI.',
            ];
        } catch (\Exception $e) {
            Log::error('GatewayAPI wallet check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => 'Error checking GatewayAPI wallet: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check legacy wallet One balance.
     */
    protected function checkOneBalance(): array
    {
        return $this->checkLegacyWalletBalance('walletOneApi', 'walletOneProvider', 'walletOneProviderName');
    }

    /**
     * Check legacy wallet Two balance.
     */
    protected function checkTwoBalance(): array
    {
        return $this->checkLegacyWalletBalance('walletTwoApi', 'walletTwoProvider', 'walletTwoProviderName');
    }

    /**
     * Check legacy wallet Three balance.
     */
    protected function checkThreeBalance(): array
    {
        return $this->checkLegacyWalletBalance('walletThreeApi', 'walletThreeProvider', 'walletThreeProviderName');
    }

    /**
     * Generic method to check legacy wallet balances.
     */
    protected function checkLegacyWalletBalance(string $apiKeyName, string $providerUrlName, string $providerNameName): array
    {
        try {
            $apiKey = $this->getApiConfig($apiKeyName);
            $hostUrl = $this->getApiConfig($providerUrlName);
            $providerName = $this->getApiConfig($providerNameName);

            if (empty($apiKey) || empty($hostUrl)) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => "Legacy wallet credentials not configured ({$providerNameName}).",
                ];
            }

            // Determine auth type based on provider
            $authType = 'Token';
            $accMethod = 0; // GET
            if (strpos($hostUrl, 'n3tdata247') !== false) {
                $authType = 'Basic';
                $accMethod = 1; // POST
            } elseif (strpos($hostUrl, 'bilalsadasub') !== false || strpos($hostUrl, 'n3tdata') !== false) {
                $authType = 'Basic';
            }

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "{$authType} {$apiKey}",
                ])
                ->withOptions(['verify' => false])
                ->{$accMethod === 1 ? 'post' : 'get'}($hostUrl);

            if (!$response->successful()) {
                return [
                    'status' => 'fail',
                    'balance' => 0,
                    'message' => "Failed to connect to {$providerName} API.",
                ];
            }

            $result = $response->json();

            // Check various balance field locations
            $balance = null;
            if (isset($result['user']['wallet_balance'])) {
                $balance = (float) $result['user']['wallet_balance'];
            } elseif (isset($result['wallet_balance'])) {
                $balance = (float) $result['wallet_balance'];
            } elseif (isset($result['balance'])) {
                $balance = (float) $result['balance'];
            }

            if ($balance !== null) {
                return [
                    'status' => 'success',
                    'balance' => round($balance, 2),
                    'message' => 'Balance retrieved successfully.',
                ];
            }

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => "Invalid response from {$providerName} API.",
            ];
        } catch (\Exception $e) {
            Log::error("Legacy wallet check failed ({$providerNameName})", ['error' => $e->getMessage()]);

            return [
                'status' => 'fail',
                'balance' => 0,
                'message' => "Error checking {$providerNameName} wallet: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Get API config value from database.
     */
    protected function getApiConfig(string $name): ?string
    {
        static $configs = null;

        if ($configs === null) {
            $configs = ApiConfig::all();
        }

        foreach ($configs as $config) {
            if ($config->name === $name) {
                return $config->value;
            }
        }

        return null;
    }
}
