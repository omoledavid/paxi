<?php

namespace App\Services\NelloBytes;

use App\Exceptions\NelloBytesApiException;
use App\Exceptions\NelloBytesInsufficientBalanceException;
use App\Exceptions\NelloBytesInvalidCustomerException;

class SpectranetService extends NelloBytesClient
{
    /**
     * Buy Spectranet bundle
     *
     * @throws NelloBytesApiException
     * @throws NelloBytesInsufficientBalanceException
     * @throws NelloBytesInvalidCustomerException
     */
    public function buyBundle(string $customerId, string $packageCode, string $transactionRef): array
    {
        $endpoint = config('nellobytes.endpoints.spectranet.buy');

        $params = [
            'CustomerID' => $customerId,
            'Package' => $packageCode,
            'Reference' => $transactionRef,
        ];

        return $this->makeRequest($endpoint, $params, 'POST');
    }

    /**
     * Get Spectranet packages (cached for 24 hours)
     *
     * @throws NelloBytesApiException
     */
    public function getPackages(): array
    {
        $cacheKey = 'nellobytes:spectranet:packages';

        return $this->remember($cacheKey, function () {
            $endpoint = config('nellobytes.endpoints.spectranet.packages');
            $response = $this->makeRequest($endpoint, [], 'GET');

            return $response['data'] ?? $response['packages'] ?? $response;
        });
    }

    /**
     * Clear Spectranet packages cache
     */
    public function clearPackagesCache(): void
    {
        $this->clearCache('nellobytes:spectranet:packages');
    }
}
