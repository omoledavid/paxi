<?php

namespace App\Services\VtuAfrica;

use App\Exceptions\VtuAfricaApiException;

class CableTvService extends VtuAfricaClient
{
    /**
     * Verify cable TV smartcard/IUC number
     *
     * @throws VtuAfricaApiException
     */
    public function verifySmartcard(
        string $service,
        string $smartNo,
        string $variation
    ): array {
        $endpoint = config('vtuafrica.endpoints.cabletv.verify');

        $params = [
            'serviceName' => 'CableTV',
            'service' => strtolower($service),
            'smartNo' => $smartNo,
            'variation' => $variation,
        ];

        $response = $this->makeRequest($endpoint, $params, 'GET');

        $description = $response['description'] ?? [];

        return [
            'status' => 'success',
            'customer_name' => $description['Customer'] ?? null,
            'service' => $description['Service'] ?? $service,
            'smart_no' => $description['SmartNo'] ?? $smartNo,
            'current_bouquet' => $description['Current_Bouquet'] ?? null,
            'current_status' => $description['Current_Status'] ?? null,
            'due_date' => $description['Due_Date'] ?? null,
            'message' => $description['message'] ?? 'Verification Successful',
            'raw_response' => $response,
        ];
    }

    /**
     * Purchase cable TV subscription
     *
     * @throws VtuAfricaApiException
     */
    public function purchaseSubscription(
        string $service,
        string $smartNo,
        string $variation,
        string $transactionRef,
        ?float $maxAmount = null,
        ?string $webhookUrl = null
    ): array {
        $endpoint = config('vtuafrica.endpoints.cabletv.purchase');

        $params = [
            'service' => strtolower($service),
            'smartNo' => $smartNo,
            'variation' => $variation,
            'ref' => $transactionRef,
        ];

        if ($maxAmount !== null) {
            $params['maxamount'] = $maxAmount;
        }

        if ($webhookUrl !== null) {
            $params['webhookURL'] = $webhookUrl;
        }

        $response = $this->makeRequest($endpoint, $params, 'GET');

        $description = $response['description'] ?? [];

        return [
            'status' => 'success',
            'product_name' => $description['ProductName'] ?? null,
            'smart_no' => $description['SmartNo'] ?? $smartNo,
            'amount_charged' => $description['Amount_Charged'] ?? null,
            'previous_balance' => $description['Previous_Balance'] ?? null,
            'current_balance' => $description['Current_Balance'] ?? null,
            'reference' => $description['ReferenceID'] ?? $transactionRef,
            'message' => $description['message'] ?? 'Subscription Successful',
            'raw_response' => $response,
        ];
    }

    /**
     * Map provider ID/name to VTU Africa service code
     */
    public static function mapServiceCode(string $providerId): ?string
    {
        $serviceMap = [
            // DB ID -> VTU Africa Service ID
            '1' => 'gotv',
            '2' => 'dstv',
            '3' => 'startimes',
            '4' => 'showmax',
            // DB Name -> VTU Africa Service ID
            'GOTV' => 'gotv',
            'DSTV' => 'dstv',
            'STARTIMES' => 'startimes',
            'SHOWMAX' => 'showmax',
            'gotv' => 'gotv',
            'dstv' => 'dstv',
            'startimes' => 'startimes',
            'showmax' => 'showmax',
        ];

        return $serviceMap[$providerId] ?? strtolower($providerId);
    }
}
