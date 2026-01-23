<?php

namespace App\Services\Paystack;

use App\Exceptions\PaystackApiException;
use App\Models\ApiConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackClient
{
    protected string $baseUrl;

    protected string $secretKey;

    protected $config;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.paystack.payment_url', 'https://api.paystack.co'), '/');
        $this->config = ApiConfig::all();
        $this->secretKey = getConfigValue($this->config, 'paystackApi');
    }

    /**
     * Make a request to Paystack API
     *
     * @throws PaystackApiException
     */
    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $url = $this->baseUrl.'/'.ltrim($endpoint, '/');

        Log::info('Paystack API Request', [
            'url' => $url,
            'method' => $method,
            'params' => $this->sanitizeParams($params),
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        if (strtolower($method) === 'post') {
            $response = $response->post($url, $params);
        } else {
            $response = $response->get($url, $params);
        }

        $responseBody = $response->json();

        Log::info('Paystack API Response', [
            'url' => $url,
            'status' => $response->status(),
            'body' => $responseBody,
        ]);

        if (! $response->successful()) {
            throw new PaystackApiException(
                $responseBody['message'] ?? 'Paystack API Error',
                $responseBody['code'] ?? 'API_ERROR',
                $responseBody,
                $response->status()
            );
        }

        if (! ($responseBody['status'] ?? false)) {
            throw new PaystackApiException(
                $responseBody['message'] ?? 'Paystack request failed',
                'REQUEST_FAILED',
                $responseBody,
                400
            );
        }

        return $responseBody;
    }

    protected function sanitizeParams(array $params): array
    {
        // Add sensitive keys to mask if any
        return $params;
    }
}
