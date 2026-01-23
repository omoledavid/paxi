<?php

namespace App\Services\Vtpass;

class AirtimeService extends VtpassClient
{
    public function purchaseAirtime(string $requestId, string $serviceID, string $phone, float $amount)
    {
        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceID, // mtn, glo, airtel, etisalat
            'amount' => $amount,
            'phone' => $phone,
        ];

        return $this->purchaseProduct($payload);
    }
}
