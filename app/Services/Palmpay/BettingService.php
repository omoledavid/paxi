<?php

namespace App\Services\Palmpay;

use App\Exceptions\PalmpayApiException;
use App\Exceptions\PalmpayInvalidCustomerException;

class BettingService extends PalmpayClient
{
    /**
     * Get list of betting companies from PalmPay biller/query API.
     *
     * Results are cached for the duration of palmpay.cache.ttl (default 24h).
     *
     * @throws PalmpayApiException
     */
    public function getCompanies(): array
    {
        return $this->remember('palmpay:betting:companies', function () {
            $response = $this->queryBiller('betting');

            return $response['data'] ?? [];
        });
    }

    /**
     * Resolve the correct PalmPay billerId for a given company code.
     *
     * Calls biller/query with sceneCode=betting (cached) and matches the
     * companyCode against billerId or billerName (case-insensitive).
     * Falls back to the raw companyCode if no match is found.
     *
     * @throws PalmpayApiException
     */
    public function resolveBillerId(string $companyCode): string
    {
        $companies = $this->getCompanies();

        foreach ($companies as $company) {
            $billerId   = $company['billerId'] ?? '';
            $billerName = $company['billerName'] ?? '';

            if (
                strcasecmp($billerId, $companyCode) === 0 ||
                strcasecmp($billerName, $companyCode) === 0
            ) {
                return $billerId;
            }
        }

        // No match found — return the raw value so PalmPay returns a clear error
        return $companyCode;
    }

    /**
     * Fetch and cache the correct itemId for a betting company from PalmPay item/query API.
     *
     * @throws PalmpayApiException
     */
    public function getBettingItemId(string $billerId): string
    {
        $cacheKey = 'palmpay:betting:item:' . $billerId;

        return $this->remember($cacheKey, function () use ($billerId) {
            try {
                $response = $this->queryItem('betting', $billerId);
                $items    = $response['data'] ?? [];

                // Return the first available item
                foreach ($items as $item) {
                    if (isset($item['status']) && $item['status'] == 1) {
                        return $item['itemId'];
                    }
                }
            } catch (PalmpayApiException $e) {
                // Some betting companies don't support item/query (returns INVALID_PARAMETER).
                // Fall back to billerId as itemId for these companies.
            }

            return $billerId;
        });
    }

    /**
     * Verify betting customer (Bet ID validation) via rechargeaccount/query
     *
     * @throws PalmpayApiException
     * @throws PalmpayInvalidCustomerException
     */
    public function verifyCustomer(string $companyCode, string $customerId): array
    {
        $endpoint = config('palmpay.endpoints.query_recharge_account');
        $billerId  = $this->resolveBillerId($companyCode);

        $params = [
            'sceneCode'       => 'betting',
            'rechargeAccount' => $customerId,
            'billerId'        => $billerId,
        ];

        try {
            $response = $this->makeRequest($endpoint, $params, 'POST');

            $data = $response['data'] ?? [];

            return [
                'status'        => 'success',
                'customer_name' => $data['customerName'] ?? null,
                'biller'        => $data['biller'] ?? $billerId,
                'user_id'       => $customerId,
                'message'       => $response['respMsg'] ?? 'Customer verified successfully',
                'raw_response'  => $response,
            ];
        } catch (PalmpayApiException $e) {
            $errorData = $e->getErrorData();
            $respCode  = $errorData['respCode'] ?? '';
            $message   = $errorData['respMsg'] ?? $e->getMessage();

            if (
                $respCode === 'SBP.INVALID_RECHARGE_ACCOUNT' ||
                stripos($message, 'Invalid') !== false ||
                stripos($message, 'not found') !== false
            ) {
                throw new PalmpayInvalidCustomerException($message, $errorData);
            }

            throw $e;
        }
    }

    /**
     * Fund betting account
     *
     * @param float $amount Amount in Naira (will be converted to kobo for API)
     *
     * @throws PalmpayApiException
     */
    public function fund(
        string $companyCode,
        string $customerId,
        float $amount,
        string $transactionRef,
        ?string $phone = null,
        ?string $notifyUrl = null
    ): array {
        $endpoint = config('palmpay.endpoints.create_order');
        $billerId  = $this->resolveBillerId($companyCode);

        $params = [
            'sceneCode'       => 'betting',
            'billerId'        => $billerId,
            'itemId'          => $this->getBettingItemId($billerId),
            'amount'          => (int) ($amount * 100), // Convert Naira to kobo
            'rechargeAccount' => $customerId,
            'outOrderNo'      => $transactionRef,
            'notifyUrl'       => $notifyUrl ?: config('palmpay.notify_url', ''),
        ];

        $response = $this->makeRequest($endpoint, $params, 'POST');

        $data = $response['data'] ?? [];

        return [
            'status'       => 'success',
            'order_id'     => $data['orderNo'] ?? null,
            'reference'    => $data['outOrderNo'] ?? $transactionRef,
            'order_status' => $data['orderStatus'] ?? 0,
            'amount'       => $amount,
            'customer_id'  => $customerId,
            'message'      => $response['respMsg'] ?? 'Betting account funded successfully',
            'raw_response' => $response,
        ];
    }

    /**
     * Clear the cached betting companies list and all item caches.
     */
    public function clearCompaniesCache(): void
    {
        $this->clearCache('palmpay:betting:companies');
    }

    /**
     * Clear the cached itemId for a specific betting company.
     */
    public function clearItemCache(string $billerId): void
    {
        $this->clearCache('palmpay:betting:item:' . $billerId);
    }
}
