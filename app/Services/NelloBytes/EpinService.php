<?php

namespace App\Services\NelloBytes;

use App\Exceptions\NelloBytesApiException;
use App\Exceptions\NelloBytesInsufficientBalanceException;

class EpinService extends NelloBytesClient
{
    /**
     * Purchase airtime EPIN(s)
     *
     * @param string $mobileNetwork
     * @param int $value
     * @param int $quantity
     * @param string $transactionRef
     * @param string|null $callbackUrl
     * @return array
     * @throws NelloBytesApiException
     * @throws NelloBytesInsufficientBalanceException
     */
    public function buyAirtimeEpin(
        string $mobileNetwork,
        int $value,
        int $quantity,
        string $transactionRef,
        ?string $callbackUrl = null
    ): array {
        $endpoint = config('nellobytes.endpoints.epin.print');
        $resolvedCallbackUrl = $callbackUrl ?: config('nellobytes.epin_callback_url');

        $params = [
            'MobileNetwork' => $mobileNetwork,
            'Value' => $value,
            'Quantity' => $quantity,
            'RequestID' => $transactionRef,
        ];

        if (!empty($resolvedCallbackUrl)) {
            $params['CallBackURL'] = $resolvedCallbackUrl;
        }

        // Spec requires a basic HTTPS GET request with query parameters
        return $this->makeRequest($endpoint, $params, 'GET');
    }

    /**
     * Query EPIN transaction status by RequestID or OrderID
     *
     * @param string|null $requestId
     * @param string|null $orderId
     * @return array
     * @throws NelloBytesApiException
     * @throws NelloBytesInsufficientBalanceException
     */
    public function queryTransaction(?string $requestId = null, ?string $orderId = null): array
    {
        $endpoint = config('nellobytes.endpoints.query');

        $params = [];
        if ($requestId) {
            $params['RequestID'] = $requestId;
        }
        if ($orderId) {
            $params['OrderID'] = $orderId;
        }

        return $this->makeRequest($endpoint, $params, 'GET');
    }

    /**
     * Get EPIN discounts (cached for 24 hours)
     *
     * @return array
     * @throws NelloBytesApiException
     */
    public function getDiscounts(): array
    {
        $cacheKey = 'nellobytes:epin:discounts';

        return $this->remember($cacheKey, function () {
            $endpoint = config('nellobytes.endpoints.epin.discounts');
            $response = $this->makeRequest($endpoint, [], 'GET');

            return $response['data'] ?? $response['discounts'] ?? $response;
        });
    }

    /**
     * Clear EPIN discounts cache
     *
     * @return void
     */
    public function clearDiscountsCache(): void
    {
        $this->clearCache('nellobytes:epin:discounts');
    }
}

