<?php

namespace App\Services\NelloBytes;

class DataService extends NelloBytesClient
{
    /**
     * Purchase data
     *
     * @param string $networkCode
     * @param string $dataCode
     * @param string $phoneNumber
     * @param string $transactionRef
     * @return array
     * @throws \App\Exceptions\NelloBytesApiException
     * @throws \App\Exceptions\NelloBytesInsufficientBalanceException
     */
    public function purchaseData(
        string $networkCode,
        string $dataCode,
        string $phoneNumber,
        string $transactionRef,
        ?string $callbackUrl = null
    ): array {
        $endpoint = config('nellobytes.endpoints.data.buy');

        $params = [
            'MobileNetwork' => $networkCode,
            'DataPlan' => $dataCode,
            'MobileNumber' => $phoneNumber,
            'RequestID' => $transactionRef,
        ];

        if (!empty($callbackUrl)) {
            $params['CallBackURL'] = $callbackUrl;
        }

        return $this->makeRequest($endpoint, $params, 'POST');
    }
}