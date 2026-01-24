<?php

namespace App\Services\VtuAfrica;

use App\Exceptions\VtuAfricaApiException;
use App\Exceptions\VtuAfricaInvalidCustomerException;

class BettingService extends VtuAfricaClient
{
    /**
     * Get list of betting companies
     *
     * VTU Africa does not provide an API endpoint for this.
     * Returns static list from configuration.
     */
    public function getCompanies(): array
    {
        return config('vtuafrica.betting_companies', []);
    }

    /**
     * Verify betting customer (Bet ID validation)
     *
     * @throws VtuAfricaApiException
     * @throws VtuAfricaInvalidCustomerException
     */
    public function verifyCustomer(string $companyCode, string $customerId): array
    {
        $endpoint = config('vtuafrica.endpoints.betting.verify');

        $params = [
            'serviceName' => 'Betting',
            'service' => strtolower($companyCode),
            'userid' => $customerId,
        ];

        try {
            $response = $this->makeRequest($endpoint, $params, 'GET');

            // Extract description for customer details
            $description = $response['description'] ?? [];

            return [
                'status' => 'success',
                'customer_name' => $description['Customer'] ?? null,
                'service' => $description['Service'] ?? $companyCode,
                'user_id' => $description['UserID'] ?? $customerId,
                'message' => $description['message'] ?? 'BETID Verified Successfully',
                'raw_response' => $response,
            ];
        } catch (VtuAfricaApiException $e) {
            // Check if it's an invalid customer error
            $errorData = $e->getErrorData();
            $description = $errorData['description'] ?? '';

            if (
                stripos($description, 'Invalid Bet ID') !== false ||
                stripos($description, 'Invalid') !== false
            ) {
                throw new VtuAfricaInvalidCustomerException($description, $errorData);
            }

            throw $e;
        }
    }

    /**
     * Fund betting account
     *
     * @throws VtuAfricaApiException
     */
    public function fund(
        string $companyCode,
        string $customerId,
        float $amount,
        string $transactionRef,
        ?string $phone = null,
        ?string $webhookUrl = null
    ): array {
        $endpoint = config('vtuafrica.endpoints.betting.fund');

        $params = [
            'service' => strtolower($companyCode),
            'userid' => $customerId,
            'amount' => $amount,
            'ref' => $transactionRef,
        ];

        if ($phone) {
            $params['phone'] = $phone;
        }

        if ($webhookUrl) {
            $params['webhookURL'] = $webhookUrl;
        }

        $response = $this->makeRequest($endpoint, $params, 'GET');

        $description = $response['description'] ?? [];

        return [
            'status' => 'success',
            'service' => $description['Service'] ?? $companyCode,
            'user_id' => $description['UserID'] ?? $customerId,
            'amount_requested' => $description['Request_Amount'] ?? $amount,
            'charge' => $description['Charge'] ?? 0,
            'amount_charged' => $description['Amount_Charged'] ?? $amount,
            'previous_balance' => $description['Previous_Balance'] ?? null,
            'current_balance' => $description['Current_Balance'] ?? null,
            'reference' => $description['ReferenceID'] ?? $transactionRef,
            'message' => $description['message'] ?? 'Transaction Successful',
            'raw_response' => $response,
        ];
    }

    /**
     * Clear betting companies cache (no-op since static list)
     */
    public function clearCompaniesCache(): void
    {
        // No cache to clear since companies are static
    }
}
