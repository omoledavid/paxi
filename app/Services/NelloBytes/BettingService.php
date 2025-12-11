<?php

namespace App\Services\NelloBytes;

use App\Exceptions\NelloBytesApiException;
use App\Exceptions\NelloBytesInsufficientBalanceException;
use App\Exceptions\NelloBytesInvalidCustomerException;

class BettingService extends NelloBytesClient
{
    /**
     * Fund a betting account
     *
     * @param string $companyCode
     * @param string $customerId
     * @param float $amount
     * @param string $transactionRef
     * @return array
     * @throws NelloBytesApiException
     * @throws NelloBytesInsufficientBalanceException
     * @throws NelloBytesInvalidCustomerException
     */
    public function fund(
        string $companyCode,
        string $customerId,
        float $amount,
        string $transactionRef,
        ?string $callbackUrl = null
    ): array {
        $endpoint = config('nellobytes.endpoints.betting.fund');

        $params = [
            'BettingCompany' => $companyCode,
            'CustomerID' => $customerId,
            'Amount' => $amount,
            'RequestID' => $transactionRef,
        ];

        if (!empty($callbackUrl)) {
            $params['CallBackURL'] = $callbackUrl;
        }

        return $this->makeRequest($endpoint, $params, 'POST');
    }

    /**
     * Verify betting customer
     *
     * @param string $companyCode
     * @param string $customerId
     * @return array
     * @throws NelloBytesApiException
     * @throws NelloBytesInvalidCustomerException
     */
    public function verifyCustomer(string $companyCode, string $customerId): array
    {
        $endpoint = config('nellobytes.endpoints.betting.verify');

        $params = [
            'BettingCompany' => $companyCode,
            'CustomerID' => $customerId,
        ];

        return $this->makeRequest($endpoint, $params, 'GET');
    }

    /**
     * Get betting companies (cached for 24 hours)
     *
     * @return array
     * @throws NelloBytesApiException
     */
    public function getCompanies(): array
    {
        $cacheKey = 'nellobytes:betting:companies';

        return $this->remember($cacheKey, function () {
            $endpoint = config('nellobytes.endpoints.betting.companies');
            $response = $this->makeRequest($endpoint, [], 'GET');

            return $response['data'] ?? $response['companies'] ?? $response;
        });
    }

    /**
     * Clear betting companies cache
     *
     * @return void
     */
    public function clearCompaniesCache(): void
    {
        $this->clearCache('nellobytes:betting:companies');
    }
}

