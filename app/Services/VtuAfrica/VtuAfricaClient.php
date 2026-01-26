<?php

namespace App\Services\VtuAfrica;

use App\Exceptions\VtuAfricaApiException;
use App\Models\ApiConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VtuAfricaClient
{
    protected Client $client;

    protected string $baseUrl;

    protected string $apiKey;

    protected int $timeout;

    protected int $retryAttempts;

    protected int $retryDelay;

    protected $config;

    public function __construct()
    {
        $this->config = ApiConfig::all();

        $useSandbox = config('vtuafrica.use_sandbox', false);
        $this->baseUrl = $useSandbox
            ? rtrim(config('vtuafrica.sandbox_base_url'), '/')
            : rtrim(config('vtuafrica.base_url'), '/');

        $this->apiKey = getConfigValue($this->config, 'vtuAfricaApiKey') ?: config('vtuafrica.api_key');
        $this->timeout = config('vtuafrica.timeout', 60);
        $this->retryAttempts = config('vtuafrica.retry.attempts', 3);
        $this->retryDelay = config('vtuafrica.retry.delay', 1000);

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Make a request to VTU Africa API with retry logic
     *
     * @throws VtuAfricaApiException
     */
    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        // VTU Africa uses apikey as query parameter
        $params['apikey'] = $this->apiKey;

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            try {
                $requestData = [
                    'query' => $params,
                ];

                Log::info('VTU Africa API Request', [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'params' => $this->sanitizeParams($params),
                    'attempt' => $attempt + 1,
                ]);

                $response = $this->client->request($method, $endpoint, $requestData);
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);
                $dataArray = $data ?? [];

                Log::info('VTU Africa API Response', [
                    'endpoint' => $endpoint,
                    'status_code' => $response->getStatusCode(),
                    'response' => $dataArray,
                ]);

                // VTU Africa success code is 101
                if (isset($dataArray['code']) && $dataArray['code'] == 101) {
                    return $dataArray;
                }

                // Handle empty array response (usually means invalid smartcard/customer)
                if (empty($dataArray) || $dataArray === []) {
                    throw new VtuAfricaApiException(
                        'Invalid smartcard/customer number or service unavailable',
                        'INVALID_CUSTOMER',
                        $dataArray,
                        400
                    );
                }

                // Handle error response
                $this->handleErrorResponse($dataArray);

                // If we get here, it's an unknown error
                throw new VtuAfricaApiException(
                    $dataArray['description'] ?? 'Unknown error from VTU Africa API',
                    (string) ($dataArray['code'] ?? ''),
                    $dataArray,
                    500
                );

            } catch (VtuAfricaApiException $e) {
                $lastException = $e;
                $attempt++;

                Log::error('VTU Africa API Exception', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getErrorCode(),
                    'attempt' => $attempt,
                ]);

                // Don't retry for VTU Africa API business logic errors
                // These are definitive responses, not transient network issues
                // VTU Africa error codes (e.g., 204, 400, etc.) should not be retried
                // as they indicate the transaction was processed but failed for a known reason
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

                    Log::error('VTU Africa API Error Response', [
                        'endpoint' => $endpoint,
                        'status_code' => $response->getStatusCode(),
                        'response' => $dataArray,
                        'attempt' => $attempt,
                    ]);

                    $this->handleErrorResponse($dataArray);

                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                        throw new VtuAfricaApiException(
                            $dataArray['description'] ?? $e->getMessage(),
                            (string) ($dataArray['code'] ?? ''),
                            $dataArray,
                            $response->getStatusCode()
                        );
                    }
                } else {
                    Log::error('VTU Africa API Request Exception', [
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                        'attempt' => $attempt,
                    ]);
                }

                if ($attempt >= $this->retryAttempts) {
                    throw new VtuAfricaApiException(
                        'Failed to connect to VTU Africa API after ' . $this->retryAttempts . ' attempts',
                        'CONNECTION_ERROR',
                        null,
                        500
                    );
                }

                usleep($this->retryDelay * 1000 * $attempt);

            } catch (GuzzleException $e) {
                $lastException = $e;
                $attempt++;

                Log::error('VTU Africa API Guzzle Exception', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $this->retryAttempts) {
                    throw new VtuAfricaApiException(
                        'Failed to connect to VTU Africa API: ' . $e->getMessage(),
                        'CONNECTION_ERROR',
                        null,
                        500
                    );
                }

                usleep($this->retryDelay * 1000 * $attempt);
            }
        }

        throw new VtuAfricaApiException(
            'Failed to connect to VTU Africa API after ' . $this->retryAttempts . ' attempts',
            'CONNECTION_ERROR',
            null,
            500
        );
    }

    /**
     * Handle error responses and throw appropriate exceptions
     *
     * @throws VtuAfricaApiException
     */
    protected function handleErrorResponse(?array $data): void
    {
        if (!$data) {
            return;
        }

        $code = $data['code'] ?? null;
        $description = $data['description'] ?? 'Unknown error';

        // VTU Africa error codes
        $errorMessages = [
            400 => 'Invalid request',
            401 => 'Authentication failed',
            500 => 'VTU service error',
        ];

        if ($code && $code != 101) {
            $message = is_string($description) ? $description : ($errorMessages[$code] ?? 'API Error');
            throw new VtuAfricaApiException($message, (string) $code, $data, (int) $code);
        }
    }

    /**
     * Sanitize parameters for logging (remove sensitive data)
     */
    protected function sanitizeParams(array $params): array
    {
        $sensitive = ['apikey', 'api_key', 'password', 'pin'];
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
        $ttl = $ttl ?? config('vtuafrica.cache.ttl', 86400);

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
