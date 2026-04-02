<?php

namespace App\Services\Palmpay;

use App\Exceptions\PalmpayApiException;
use App\Models\ApiConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PalmpayClient
{
    protected Client $client;

    protected string $baseUrl;

    protected string $appId;

    protected string $merchantPrivateKey;

    protected string $platformPublicKey;

    protected int $timeout;

    protected int $retryAttempts;

    protected int $retryDelay;

    protected $config;

    public function __construct()
    {
        $this->config = ApiConfig::all();

        $useSandbox = config('palmpay.use_sandbox', false);
        $this->baseUrl = $useSandbox
            ? rtrim(config('palmpay.sandbox_base_url'), '/')
            : rtrim(config('palmpay.base_url'), '/');

        $this->appId = getConfigValue($this->config, 'palmpayAppId') ?: config('palmpay.app_id', '');

        $this->merchantPrivateKey = getConfigValue($this->config, 'palmpayMerchantSigningKey') ?: config('palmpay.merchant_private_key', '');

        // The existing palmpayMerchantPrivateKey in ApiConfig is actually the PalmPay platform public key
        $this->platformPublicKey = getConfigValue($this->config, 'palmpayMerchantPrivateKey') ?: config('palmpay.platform_public_key', '');

        $this->timeout = config('palmpay.timeout', 60);
        $this->retryAttempts = config('palmpay.retry.attempts', 3);
        $this->retryDelay = config('palmpay.retry.delay', 1000);

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->appId,
                'CountryCode' => 'NG',
            ],
        ]);
    }

    /**
     * Make a request to PalmPay API with retry logic
     *
     * @throws PalmpayApiException
     */
    protected function makeRequest(string $endpoint, array $params = [], string $method = 'POST'): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            try {
                // Inject mandatory PalmPay request fields (fresh nonce per attempt)
                $enrichedParams = array_merge([
                    'requestTime' => (int) round(microtime(true) * 1000),
                    'nonceStr' => bin2hex(random_bytes(16)),
                    'version' => 'V2',
                ], $params);

                // Strip null/empty values so they are never sent in the JSON body.
                // This ensures PalmPay's server builds the exact same signature string we do.
                $enrichedParams = array_filter($enrichedParams, fn($v) => $v !== null && $v !== '');

                $signature = $this->generateSignature($enrichedParams);

                $requestData = $method === 'GET'
                    ? ['query' => $enrichedParams, 'headers' => ['Signature' => $signature]]
                    : ['json' => $enrichedParams, 'headers' => ['Signature' => $signature]];

                Log::info('PalmPay API Request', [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'params' => $this->sanitizeParams($enrichedParams),
                    'attempt' => $attempt + 1,
                ]);

                $response = $this->client->request($method, $endpoint, $requestData);
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);
                $dataArray = $data ?? [];

                Log::info('PalmPay API Response', [
                    'endpoint' => $endpoint,
                    'status_code' => $response->getStatusCode(),
                    'response' => $dataArray,
                ]);

                // Handle empty response
                if (empty($dataArray) || $dataArray === []) {
                    throw new PalmpayApiException(
                        'Empty response from PalmPay API',
                        'EMPTY_RESPONSE',
                        $dataArray,
                        400
                    );
                }

                // PalmPay success: respCode "00000000" and status true
                $respCode = $dataArray['respCode'] ?? null;
                if ($respCode === '00000000' && ($dataArray['status'] ?? false) === true) {
                    return $dataArray;
                }

                // Handle error response
                $this->handleErrorResponse($dataArray);

            } catch (PalmpayApiException $e) {
                $lastException = $e;
                $attempt++;

                Log::error('PalmPay API Exception', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getErrorCode(),
                    'attempt' => $attempt,
                ]);

                // Don't retry for business logic errors
                $apiErrorCode = $e->getErrorCode();
                if (!empty($apiErrorCode) && $apiErrorCode !== 'CONNECTION_ERROR') {
                    throw $e;
                }

                // Don't retry for client errors (4xx)
                if ($e->getCode() >= 400 && $e->getCode() < 500) {
                    throw $e;
                }

                if ($attempt >= $this->retryAttempts) {
                    throw $e;
                }

                // Exponential backoff
                usleep($this->retryDelay * 1000 * $attempt);

            } catch (RequestException $e) {
                $lastException = $e;
                $attempt++;

                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $body = $response->getBody()->getContents();
                    $data = json_decode($body, true);
                    $dataArray = $data ?? [];

                    Log::error('PalmPay API Error Response', [
                        'endpoint' => $endpoint,
                        'status_code' => $response->getStatusCode(),
                        'response' => $dataArray,
                        'attempt' => $attempt,
                    ]);

                    $this->handleErrorResponse($dataArray);

                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                        throw new PalmpayApiException(
                            $dataArray['message'] ?? $e->getMessage(),
                            (string) ($dataArray['code'] ?? ''),
                            $dataArray,
                            $response->getStatusCode()
                        );
                    }
                } else {
                    Log::error('PalmPay API Request Exception', [
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                        'attempt' => $attempt,
                    ]);
                }

                if ($attempt >= $this->retryAttempts) {
                    throw new PalmpayApiException(
                        'Failed to connect to PalmPay API after ' . $this->retryAttempts . ' attempts',
                        'CONNECTION_ERROR',
                        null,
                        500
                    );
                }

                usleep($this->retryDelay * 1000 * $attempt);

            } catch (GuzzleException $e) {
                $lastException = $e;
                $attempt++;

                Log::error('PalmPay API Guzzle Exception', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $this->retryAttempts) {
                    throw new PalmpayApiException(
                        'Failed to connect to PalmPay API: ' . $e->getMessage(),
                        'CONNECTION_ERROR',
                        null,
                        500
                    );
                }

                usleep($this->retryDelay * 1000 * $attempt);
            }
        }

        throw new PalmpayApiException(
            'Failed to connect to PalmPay API after ' . $this->retryAttempts . ' attempts',
            'CONNECTION_ERROR',
            null,
            500
        );
    }

    /**
     * Handle error responses and throw appropriate exceptions
     *
     * @throws PalmpayApiException
     */
    protected function handleErrorResponse(?array $data): void
    {
        if (!$data) {
            return;
        }

        $respCode = $data['respCode'] ?? null;
        $message = $data['respMsg'] ?? $data['message'] ?? 'Unknown error';

        // respCode "00000000" is success; anything else is an error
        if ($respCode && $respCode !== '00000000') {
            throw new PalmpayApiException(
                is_string($message) ? $message : 'PalmPay API Error',
                (string) $respCode,
                $data,
                500
            );
        }

        // Fallback: status field is false
        if (isset($data['status']) && $data['status'] === false) {
            throw new PalmpayApiException(
                is_string($message) ? $message : 'PalmPay API Error',
                (string) ($respCode ?? 'UNKNOWN'),
                $data,
                500
            );
        }
    }

    /**
     * Generate PalmPay request signature
     *
     * Algorithm per PalmPay docs:
     * 1. Sort non-empty params by key ASCII order, join as key1=value1&key2=value2
     * 2. MD5 the string, convert to uppercase
     * 3. Sign the MD5 string with merchant private key using SHA1WithRSA
     */
    protected function generateSignature(array $params): string
    {
        // Step 1: Filter out null/empty values, sort by key ASCII order
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });
        ksort($filtered);

        // Build key=value pairs
        $parts = [];
        foreach ($filtered as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $parts[] = $key . '=' . $value;
        }
        $strA = implode('&', $parts);

        // Step 2: MD5 and uppercase
        $md5Str = strtoupper(md5($strA));

        // Step 3: Sign with RSA private key (SHA1WithRSA)
        $privateKeyPem = $this->merchantPrivateKey;

        if (empty($privateKeyPem)) {
            throw new PalmpayApiException(
                'Merchant private key is not configured (palmpayMerchantSigningKey)',
                'INVALID_KEY',
                null,
                500
            );
        }

        // Wrap in PEM format if not already wrapped
        if (strpos($privateKeyPem, '-----BEGIN') === false) {
            $wrapped = wordwrap($privateKeyPem, 64, "\n", true);

            // Try PKCS#8 first (PalmPay's documented key format)
            $pkcs8Pem = "-----BEGIN PRIVATE KEY-----\n{$wrapped}\n-----END PRIVATE KEY-----";
            $privateKeyResource = openssl_pkey_get_private($pkcs8Pem);

            if (!$privateKeyResource) {
                // Fall back to PKCS#1
                $pkcs1Pem = "-----BEGIN RSA PRIVATE KEY-----\n{$wrapped}\n-----END RSA PRIVATE KEY-----";
                $privateKeyResource = openssl_pkey_get_private($pkcs1Pem);
            }
        } else {
            $privateKeyResource = openssl_pkey_get_private($privateKeyPem);
        }

        if (!$privateKeyResource) {
            Log::error('PalmPay: Invalid merchant private key', [
                'openssl_error' => openssl_error_string(),
                'key_prefix' => substr($privateKeyPem, 0, 40),
            ]);
            throw new PalmpayApiException(
                'Invalid merchant private key configuration',
                'INVALID_KEY',
                null,
                500
            );
        }

        $signature = '';
        $signed = openssl_sign($md5Str, $signature, $privateKeyResource, OPENSSL_ALGO_SHA1);

        if (!$signed) {
            Log::error('PalmPay: Failed to generate signature', [
                'openssl_error' => openssl_error_string(),
            ]);
            throw new PalmpayApiException(
                'Failed to generate request signature',
                'SIGNATURE_ERROR',
                null,
                500
            );
        }

        return base64_encode($signature);
    }

    /**
     * Query billers by scene code (airtime, data, betting)
     *
     * @throws PalmpayApiException
     */
    public function queryBiller(string $sceneCode): array
    {
        $endpoint = config('palmpay.endpoints.query_biller');

        return $this->makeRequest($endpoint, ['sceneCode' => $sceneCode], 'POST');
    }

    /**
     * Query items by scene code and biller ID
     *
     * @throws PalmpayApiException
     */
    public function queryItem(string $sceneCode, string $billerId): array
    {
        $endpoint = config('palmpay.endpoints.query_item');

        return $this->makeRequest($endpoint, [
            'sceneCode' => $sceneCode,
            'billerId' => $billerId,
        ], 'POST');
    }

    /**
     * Verify callback signature using PalmPay platform public key
     */
    public function verifyCallbackSignature(array $params, string $sign): bool
    {
        $sign = urldecode($sign);

        // Remove 'sign' from params, filter empty, sort by key
        unset($params['sign']);
        $filtered = array_filter($params, fn($v) => $v !== null && $v !== '');
        ksort($filtered);

        $parts = [];
        foreach ($filtered as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
            $parts[] = $key . '=' . $value;
        }
        $strA = implode('&', $parts);
        $md5Str = strtoupper(md5($strA));

        $publicKeyPem = $this->platformPublicKey;
        if (strpos($publicKeyPem, '-----BEGIN') === false) {
            $wrapped = wordwrap($publicKeyPem, 64, "\n", true);
            $publicKeyPem = "-----BEGIN PUBLIC KEY-----\n{$wrapped}\n-----END PUBLIC KEY-----";
        }

        $publicKeyResource = openssl_pkey_get_public($publicKeyPem);
        if (!$publicKeyResource) {
            Log::error('PalmPay: Invalid platform public key');
            return false;
        }

        return openssl_verify($md5Str, base64_decode($sign), $publicKeyResource, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * Sanitize parameters for logging (remove sensitive data)
     */
    protected function sanitizeParams(array $params): array
    {
        $sensitive = ['appId', 'app_id', 'merchantPrivateKey', 'password', 'pin', 'Authorization'];
        $sanitized = $params;

        foreach ($sensitive as $key) {
            if (isset($sanitized[$key])) {
                $sanitized[$key] = '***';
            }
        }

        return $sanitized;
    }

    /**
     * Get cached data or fetch and cache
     */
    protected function remember(string $cacheKey, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? config('palmpay.cache.ttl', 86400);

        return Cache::remember($cacheKey, $ttl, $callback);
    }

    /**
     * Clear cache by key
     */
    protected function clearCache(string $cacheKey): void
    {
        Cache::forget($cacheKey);
    }
}
