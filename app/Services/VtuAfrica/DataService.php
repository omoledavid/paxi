<?php

namespace App\Services\VtuAfrica;

use App\Exceptions\VtuAfricaApiException;

class DataService extends VtuAfricaClient
{
    /**
     * Purchase data via VTU Africa API
     *
     * @throws VtuAfricaApiException
     */
    public function purchaseData(
        string $service,
        string $phoneNumber,
        string $dataPlan,
        string $transactionRef,
        ?float $maxAmount = null
    ): array {
        $endpoint = config('vtuafrica.endpoints.data.purchase');

        $params = [
            'service' => $service,
            'MobileNumber' => $phoneNumber,
            'DataPlan' => $dataPlan,
            'ref' => $transactionRef,
        ];

        if ($maxAmount !== null) {
            $params['maxamount'] = $maxAmount;
        }

        $response = $this->makeRequest($endpoint, $params, 'GET');

        $description = $response['description'] ?? [];

        return [
            'status' => 'success',
            'product_name' => $description['ProductName'] ?? null,
            'data_plan_id' => $description['DataPlanID'] ?? $dataPlan,
            'data_size' => $description['DataSize'] ?? null,
            'validity' => $description['Validity'] ?? null,
            'amount_charged' => $description['Amount_Charged'] ?? null,
            'previous_balance' => $description['Previous_Balance'] ?? null,
            'current_balance' => $description['Current_Balance'] ?? null,
            'phone_number' => $description['MobileNumber'] ?? $phoneNumber,
            'reference' => $description['ReferenceID'] ?? $transactionRef,
            'message' => $description['message'] ?? 'Data purchase successful',
            'transaction_date' => $description['transaction_date'] ?? null,
            'raw_response' => $response,
        ];
    }

    /**
     * Map network ID to VTU Africa service code
     * 
     * Data types: SME, Gifting, Corporate map to different service codes
     */
    public static function mapServiceCode(string $networkId, string $dataType = 'SME'): ?string
    {
        $serviceMap = [
            '1' => [ // MTN
                'SME' => 'MTNSME',
                'Gifting' => 'MTNGIFTING',
                'Corporate' => 'MTNCOUPON',
            ],
            '2' => [ // GLO
                'SME' => 'GLOSME',
                'Gifting' => 'GLOSME',
                'Corporate' => 'GLOSME',
            ],
            '3' => [ // 9Mobile
                'SME' => '9MOBILESME',
                'Gifting' => '9MOBILESME',
                'Corporate' => '9MOBILESME',
            ],
            '4' => [ // Airtel
                'SME' => 'AIRTELSME',
                'Gifting' => 'AIRTELGIFTING',
                'Corporate' => 'AIRTELSME',
            ],
        ];

        return $serviceMap[$networkId][$dataType] ?? $serviceMap[$networkId]['SME'] ?? null;
    }
}
