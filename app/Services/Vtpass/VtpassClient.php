<?php

namespace App\Services\Vtpass;

use App\Exceptions\VtpassApiException;
use App\Models\ApiConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class VtpassClient
{
    protected Client $client;
    protected string $baseUrl;
    protected string $apiKey;
    protected string $secretKey;
    protected string $publicKey;
    protected $config;

    public function __construct()
    {
        $this->baseUrl = config('vtpass.base_url');
        $this->config = ApiConfig::all();
        $this->apiKey = getConfigValue($this->config, 'vtApiKey');
        $this->secretKey = getConfigValue($this->config, 'vtSecretKey');
        $this->publicKey = getConfigValue($this->config, 'vtPublicKey');

        $headers = [
            'Content-Type' => 'application/json',
        ];

        
            $headers['api-key'] = $this->apiKey;
            $headers['secret-key'] = $this->secretKey;

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('vtpass.timeout', 60),
            'headers' => $headers,
        ]);
    }

    /**
     * Make a request to VTpass API
     */
    protected function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        try {
            $options = [];
            if (!empty($data)) {
                $options['json'] = $data;
            }

            // Set headers based on method
            $headers = [];
            if (strtoupper($method) === 'GET') {
                $headers['api-key'] = $this->apiKey;
                $headers['public-key'] = $this->publicKey;
            } else {
                // POST and others
                $headers['api-key'] = $this->apiKey;
                $headers['secret-key'] = $this->secretKey;
            }
            $options['headers'] = $headers;

            // Log request for debugging (sanitize sensitive data if needed)
            Log::info("VTpass Request: $method $endpoint", $data);

            $response = $this->client->request($method, $endpoint, $options);
            $content = $response->getBody()->getContents();
            $decoded = json_decode($content, true);

            Log::info("VTpass Response: $endpoint", $decoded ?? []);

            return $decoded ?? [];

        } catch (GuzzleException $e) {
            Log::error("VTpass API Error: " . $e->getMessage());
            throw new VtpassApiException("VTpass API Connection Error: " . $e->getMessage());
        }
    }

    /**
     * Standard purchase request
     */
    public function purchaseProduct(array $payload)
    {
        $endpoint = config('vtpass.endpoints.pay', 'pay');
        return $this->makeRequest('POST', $endpoint, $payload);
    }

    /**
     * Query transaction status
     */
    public function queryTransaction(string $requestId)
    {
        $endpoint = config('vtpass.endpoints.query', 'requery');
        return $this->makeRequest('POST', $endpoint, ['request_id' => $requestId]);
    }
}
