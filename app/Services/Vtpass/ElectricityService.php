<?php

namespace App\Services\Vtpass;

class ElectricityService extends VtpassClient
{
    /**
     * Verify Meter Number (Merchant Verify)
     */
    public function verifyMeter(string $serviceID, string $meterNumber, string $type)
    {
        $payload = [
            'serviceID' => $serviceID, // e.g., ikeja-electric, eko-electric
            'billersCode' => $meterNumber,
            'type' => $type, // prepaid or postpaid
        ];

        $endpoint = config('vtpass.endpoints.merchant_verify', 'merchant-verify');
        return $this->makeRequest('POST', $endpoint, $payload);
    }

    /**
     * Purchase Electricity Token
     */
    public function purchaseElectricity(string $requestId, string $serviceID, string $meterNumber, string $type, float $amount, string $phone)
    {
        $payload = [
            'request_id' => $requestId,
            'serviceID' => $serviceID,
            'billersCode' => $meterNumber,
            'variation_code' => $type, // prepaid / postpaid often mapped here or just passed as type?
            // Docs say: serviceID, billersCode, variation_code (sometimes), amount, phone.
            // For electricity, usually 'type' is part of the service variation or a parameter?
            'amount' => $amount,
            'phone' => $phone,
        ];

        return $this->purchaseProduct($payload);
    }
}
