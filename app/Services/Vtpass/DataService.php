<?php

namespace App\Services\Vtpass;

class DataService extends VtpassClient
{
    public function purchaseData(string $requestId, string $serviceID, string $phone, string $variationCode, float $amount = 0)
    {
        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceID, // mtn-data, airtel-data, etc.
            'billersCode' => $phone,
            'variation_code' => $variationCode, // The specific plan code
            'phone' => $phone,
        ];

        // Some services might require amount explicitly if it's not fixed by variation (rare for data)
        if ($amount > 0) {
            $payload['amount'] = $amount;
        }

        return $this->purchaseProduct($payload);
    }

    public function getDataPlans(string $serviceID)
    {
        $endpoint = config('vtpass.endpoints.variations', 'service-variations');
        return $this->makeRequest('GET', "$endpoint?serviceID=$serviceID");
    }
}
