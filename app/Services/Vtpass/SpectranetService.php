<?php

namespace App\Services\Vtpass;

class SpectranetService extends VtpassClient
{
    public function verifyAccount(string $serviceID, string $accountNumber)
    {
        $payload = [
            'serviceID' => $serviceID, // spectranet
            'billersCode' => $accountNumber,
        ];

        $endpoint = config('vtpass.endpoints.merchant_verify', 'merchant-verify');
        return $this->makeRequest('POST', $endpoint, $payload);
    }

    public function purchase(string $requestId, string $serviceID, string $accountNumber, string $variationCode, float $amount, string $phone)
    {
        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceID, // spectranet
            'billersCode' => $accountNumber,
            'variation_code' => $variationCode,
            'amount' => $amount,
            'phone' => $phone,
        ];

        return $this->purchaseProduct($payload);
    }

    public function getVariations(string $serviceID)
    {
        $endpoint = config('vtpass.endpoints.variations', 'service-variations');
        return $this->makeRequest('GET', "$endpoint?serviceID=$serviceID");
    }
}
