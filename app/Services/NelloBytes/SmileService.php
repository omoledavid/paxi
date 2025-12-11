<?php

namespace App\Services\NelloBytes;

use App\Exceptions\NelloBytesApiException;
use App\Exceptions\NelloBytesInsufficientBalanceException;
use App\Exceptions\NelloBytesInvalidCustomerException;

class SmileService extends NelloBytesClient
{
    /**
     * Buy Smile bundle
     *
     * @param string $customerId
     * @param string $packageCode
     * @param string $transactionRef
     * @return array
     * @throws NelloBytesApiException
     * @throws NelloBytesInsufficientBalanceException
     * @throws NelloBytesInvalidCustomerException
     */
    public function buyBundle(
        string $mobileNetwork,
        string $dataPlan,
        string $mobileNumber,
        string $transactionRef,
        ?string $callbackUrl = null
    ): array {
        $endpoint = config('nellobytes.endpoints.smile.buy');

        $params = [
            'MobileNetwork' => $mobileNetwork,
            'DataPlan' => $dataPlan,
            'MobileNumber' => $mobileNumber,
            'RequestID' => $transactionRef,
        ];

        if (!empty($callbackUrl)) {
            $params['CallBackURL'] = $callbackUrl;
        }

        return $this->makeRequest($endpoint, $params, 'POST');
    }

    /**
     * Verify Smile customer
     *
     * @param string $customerId
     * @return array
     * @throws NelloBytesApiException
     * @throws NelloBytesInvalidCustomerException
     */
    public function verify(string $mobileNetwork, string $mobileNumber): array
    {
        $endpoint = config('nellobytes.endpoints.smile.verify');

        $params = [
            'MobileNetwork' => $mobileNetwork,
            'MobileNumber' => $mobileNumber,
        ];

        return $this->makeRequest($endpoint, $params, 'GET');
    }

    /**
     * Get Smile packages (cached for 24 hours)
     *
     * @return array
     * @throws NelloBytesApiException
     */
    public function getPackages(): array
    {
        $cacheKey = 'nellobytes:smile:packages';

        return $this->remember($cacheKey, function () {
            $endpoint = config('nellobytes.endpoints.smile.packages');
            $response = $this->makeRequest($endpoint, [], 'GET');

            return $response['data'] ?? $response['packages'] ?? $response;
        });
    }

    /**
     * Clear Smile packages cache
     *
     * @return void
     */
    public function clearPackagesCache(): void
    {
        $this->clearCache('nellobytes:smile:packages');
    }
}

