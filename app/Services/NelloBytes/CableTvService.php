<?php

namespace App\Services\NelloBytes;

class CableTvService extends NelloBytesClient
{
    public function purchaseCableTv(string $CableTV, string $Package, string $smartCardNo, string $PhoneNo, ?string $RequestID = null, ?string $CallBackURL = null): array
    {
        $endpoint = config('nellobytes.endpoints.cabletv.buy');

        $params = [
            'CableTV' => $CableTV,
            'Package' => $Package,
            'SmartCardNo' => $smartCardNo,
            'PhoneNo' => $PhoneNo,
            'RequestID' => $RequestID,
        ];

        if (! empty($CallBackURL)) {
            $params['CallBackURL'] = $CallBackURL;
        }

        return $this->makeRequest($endpoint, $params, 'POST');
    }

    public function getPlans(): array
    {
        $endpoint = config('nellobytes.endpoints.cabletv.plans');

        return $this->makeRequest($endpoint, [], 'GET');
    }

    public function verifyIUC(string $cableTv, string $smartCardNo): array
    {
        $endpoint = config('nellobytes.endpoints.cabletv.verify');

        $params = [
            'CableTV' => $cableTv,
            'SmartCardNo' => $smartCardNo,
        ];

        return $this->makeRequest($endpoint, $params, 'POST');
    }
}
