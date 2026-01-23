<?php

namespace App\Services\NelloBytes;

class AirtimeService extends NelloBytesClient
{
    /**
     * Purchase airtime
     *
     * @throws \App\Exceptions\NelloBytesApiException
     * @throws \App\Exceptions\NelloBytesInsufficientBalanceException
     */
    public function purchaseAirtime(
        string $networkCode,
        string $phoneNumber,
        float $amount,
        string $transactionRef,
        ?string $callbackUrl = null
    ): array {
        $endpoint = config('nellobytes.endpoints.airtime.purchase');

        $params = [
            'MobileNetwork' => $networkCode,
            'MobileNumber' => $phoneNumber,
            'Amount' => $amount,
            'RequestID' => $transactionRef,
        ];

        if (! empty($callbackUrl)) {
            $params['CallBackURL'] = $callbackUrl;
        }

        return $this->makeRequest($endpoint, $params, 'POST');
    }
}
