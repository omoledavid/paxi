<?php

namespace App\Services\Vtpass;

class TvSubscriptionService extends VtpassClient
{
    public function verifySmartcard(string $serviceID, string $smartcardNumber)
    {
        $payload = [
            'serviceID' => $serviceID, // dstv, gotv, startimes, showmax
            'billersCode' => $smartcardNumber,
        ];

        $endpoint = config('vtpass.endpoints.merchant_verify', 'merchant-verify');
        return $this->makeRequest('POST', $endpoint, $payload);
    }

    public function purchaseSubscription(string $requestId, string $serviceID, string $smartcardNumber, string $variationCode, float $amount = 0, string $phone = '')
    {
        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceID,
            'billersCode' => $smartcardNumber,
            'variation_code' => $variationCode,
            'phone' => $phone,
        ];

        if ($amount > 0) {
            $payload['amount'] = $amount;
        }

        return $this->purchaseProduct($payload);
    }

    public function getVariations(string $serviceID)
    {
        $endpoint = config('vtpass.endpoints.variations', 'service-variations');
        return $this->makeRequest('GET', "$endpoint?serviceID=$serviceID");
    }
}
