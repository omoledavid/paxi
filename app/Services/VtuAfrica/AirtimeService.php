<?php

namespace App\Services\VtuAfrica;

use App\Exceptions\VtuAfricaApiException;

class AirtimeService extends VtuAfricaClient
{
    /**
     * Purchase airtime via VTU Africa API
     *
     * @throws VtuAfricaApiException
     */
    public function purchaseAirtime(
        string $network,
        string $phoneNumber,
        float $amount,
        string $transactionRef
    ): array {
        $endpoint = config('vtuafrica.endpoints.airtime.purchase');

        $params = [
            'network' => strtolower($network),
            'phone' => $phoneNumber,
            'amount' => $amount,
            'ref' => $transactionRef,
        ];

        $response = $this->makeRequest($endpoint, $params, 'GET');

        $description = $response['description'] ?? [];

        return [
            'status' => 'success',
            'product_name' => $description['ProductName'] ?? null,
            'amount' => $description['amount'] ?? $amount,
            'amount_charged' => $description['Amount_Charged'] ?? $amount,
            'previous_balance' => $description['Previous_Balance'] ?? null,
            'current_balance' => $description['Current_Balance'] ?? null,
            'phone_number' => $description['MobileNumber'] ?? $phoneNumber,
            'reference' => $description['ReferenceID'] ?? $transactionRef,
            'message' => $description['message'] ?? 'Recharge Successful',
            'transaction_date' => $description['transaction_date'] ?? null,
            'raw_response' => $response,
        ];
    }

    /**
     * Map network ID to VTU Africa network code
     */
    public static function mapNetworkCode(string $networkId): ?string
    {
        $networkMap = [
            '1' => 'mtn',
            '2' => 'glo',
            '3' => '9mobile',
            '4' => 'airtel',
        ];

        return $networkMap[$networkId] ?? null;
    }
}
