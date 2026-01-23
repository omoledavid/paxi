<?php

namespace App\Services\Vtpass;

class SmileService extends VtpassClient
{
    public function verifyEmail(string $serviceID, string $email)
    {
        $payload = [
            'serviceID' => $serviceID, // smile-direct
            'billersCode' => $email,
        ];

        $endpoint = config('vtpass.endpoints.merchant_verify', 'merchant-verify');
        return $this->makeRequest('POST', $endpoint, $payload);
    }

    public function purchaseBundle(string $requestId, string $serviceID, string $billersCode, string $variationCode, float $amount, string $phone)
    {
        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceID, // smile-direct or smile-bundle
            'billersCode' => $billersCode, // Account ID or Phone or Email
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
