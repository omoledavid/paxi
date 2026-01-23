<?php

namespace App\Services\NelloBytes;

use App\Exceptions\NelloBytesApiException;
use App\Exceptions\NelloBytesInsufficientBalanceException;
use App\Exceptions\NelloBytesInvalidCustomerException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NelloBytesClient
{
    protected Client $client;

    protected string $baseUrl;

    protected string $userId;

    protected string $apiKey;

    protected int $timeout;

    protected int $retryAttempts;

    protected int $retryDelay;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('nellobytes.base_url'), '/');
        $this->userId = config('nellobytes.user_id');
        $this->apiKey = config('nellobytes.api_key');
        $this->timeout = config('nellobytes.timeout', 30);
        $this->retryAttempts = config('nellobytes.retry.attempts', 3);
        $this->retryDelay = config('nellobytes.retry.delay', 1000);

        $this->client = new Client([
            'base_uri' => $this->baseUrl.'/',
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Make a request to NelloBytes API with retry logic
     *
     * @throws NelloBytesApiException
     * @throws NelloBytesInsufficientBalanceException
     * @throws NelloBytesInvalidCustomerException
     */
    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET'): array
    {
        $params = array_merge([
            'UserID' => $this->userId,
            'APIKey' => $this->apiKey,
        ], $params);

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            try {
                // NelloBytes API expects query parameters for both GET and POST
                $requestData = [
                    'query' => $params,
                ];

                Log::info('NelloBytes API Request', [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'params' => $this->sanitizeParams($params),
                    'attempt' => $attempt + 1,
                ]);

                $response = $this->client->request($method, $endpoint, $requestData);
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);
                $dataArray = $data ?? [];

                Log::info('NelloBytes API Response', [
                    'endpoint' => $endpoint,
                    'status_code' => $response->getStatusCode(),
                    'response' => $dataArray,
                ]);
                // Handle NelloBytes response format
                // Some endpoints return status: 'success', others return data directly
                if (isset($dataArray['status']) && $dataArray['status'] === 'success') {
                    return $dataArray;
                }

                // If HTTP 200 and response contains data (not an error), treat as successful
                // This handles list endpoints (companies, packages, discounts) that return data directly
                if ($response->getStatusCode() === 200 && ! empty($dataArray)) {
                    // Check if it looks like an error response
                    $hasErrorIndicators = isset($dataArray['status']) && in_array(strtolower($dataArray['status']), ['fail', 'failed', 'error'])
                        || isset($dataArray['msg']) && ! empty($dataArray['msg'])
                        || isset($dataArray['message']) && ! empty($dataArray['message'])
                        || isset($dataArray['error']) && ! empty($dataArray['error']);

                    // If it doesn't look like an error and has data, treat as successful
                    if (! $hasErrorIndicators) {
                        return $dataArray;
                    }
                }

                // Check for known error codes
                $this->handleErrorResponse($dataArray);

                // If we get here, it's an unknown error
                $dataArray = $dataArray ?? [];
                throw new NelloBytesApiException(
                    (($dataArray ?? [])['msg'] ?? ($dataArray ?? [])['message'] ?? 'Unknown error from NelloBytes API'),
                    ($dataArray ?? [])['code'] ?? '',
                    $dataArray,
                    500
                );

            } catch (NelloBytesApiException $e) {
                // Handle NelloBytes API exceptions (non-success responses with HTTP 200)
                $lastException = $e;
                $attempt++;

                Log::error('NelloBytes API Exception', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getErrorCode(),
                    'attempt' => $attempt,
                ]);

                // Don't retry for client errors (4xx) or known business logic errors
                if ($e->getCode() >= 400 && $e->getCode() < 500) {
                    throw $e;
                }

                // If this is the last attempt, throw the exception
                if ($attempt >= $this->retryAttempts) {
                    throw $e;
                }

                // Wait before retrying (exponential backoff)
                usleep($this->retryDelay * 1000 * $attempt);

            } catch (RequestException $e) {
                $lastException = $e;
                $attempt++;

                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    $body = $response->getBody()->getContents();
                    $data = json_decode($body, true);
                    $dataArray = $data ?? [];

                    Log::error('NelloBytes API Error Response', [
                        'endpoint' => $endpoint,
                        'status_code' => $response->getStatusCode(),
                        'response' => $dataArray,
                        'attempt' => $attempt,
                    ]);

                    // Check for known error codes in error response
                    $this->handleErrorResponse($dataArray);

                    // If it's a client error (4xx), don't retry
                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                        throw new NelloBytesApiException(
                            (($dataArray ?? [])['msg'] ?? ($dataArray ?? [])['message'] ?? $e->getMessage()),
                            ($dataArray ?? [])['code'] ?? '',
                            $dataArray ?? [],
                            $response->getStatusCode()
                        );
                    }
                } else {
                    Log::error('NelloBytes API Request Exception', [
                        'endpoint' => $endpoint,
                        'error' => $e->getMessage(),
                        'attempt' => $attempt,
                    ]);
                }

                // If this is the last attempt, throw the exception
                if ($attempt >= $this->retryAttempts) {
                    throw new NelloBytesApiException(
                        'Failed to connect to NelloBytes API after '.$this->retryAttempts.' attempts',
                        'CONNECTION_ERROR',
                        null,
                        500
                    );
                }

                // Wait before retrying (exponential backoff)
                usleep($this->retryDelay * 1000 * $attempt);

            } catch (GuzzleException $e) {
                $lastException = $e;
                $attempt++;

                Log::error('NelloBytes API Guzzle Exception', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $this->retryAttempts) {
                    throw new NelloBytesApiException(
                        'Failed to connect to NelloBytes API: '.$e->getMessage(),
                        'CONNECTION_ERROR',
                        null,
                        500
                    );
                }

                usleep($this->retryDelay * 1000 * $attempt);
            }
        }

        throw new NelloBytesApiException(
            'Failed to connect to NelloBytes API after '.$this->retryAttempts.' attempts',
            'CONNECTION_ERROR',
            null,
            500
        );
    }

    /**
     * Handle error responses and throw appropriate exceptions
     *
     * @throws NelloBytesInsufficientBalanceException
     * @throws NelloBytesInvalidCustomerException
     * @throws NelloBytesApiException
     */
    protected function handleErrorResponse(?array $data): void
    {
        if (! $data) {
            return;
        }

        $errorCode = $data['code'] ?? $data['errorCode'] ?? '';
        $message = $data['msg'] ?? $data['message'] ?? 'Unknown error';

        // Map known error codes to exceptions
        $errorCodeMap = [
            'INSUFFICIENT_WALLET_BALANCE' => NelloBytesInsufficientBalanceException::class,
            'INVALID_CUSTOMERID' => NelloBytesInvalidCustomerException::class,
            'INVALID_CUSTOMER_ID' => NelloBytesInvalidCustomerException::class,
        ];

        // Check if message contains error codes (case-insensitive)
        $upperMessage = strtoupper($message);
        foreach ($errorCodeMap as $code => $exceptionClass) {
            if (stripos($upperMessage, $code) !== false || stripos($upperMessage, str_replace('_', ' ', $code)) !== false) {
                throw new $exceptionClass($message, $data);
            }
        }

        // Check error code directly
        if ($errorCode && isset($errorCodeMap[$errorCode])) {
            throw new $errorCodeMap[$errorCode]($message, $data);
        }
    }

    /**
     * Sanitize parameters for logging (remove sensitive data)
     */
    protected function sanitizeParams(array $params): array
    {
        $sensitive = ['APIKey', 'api_key', 'password', 'pin'];
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
        $ttl = $ttl ?? config('nellobytes.cache.ttl', 86400);

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
