<?php

namespace App\Services\NelloBytes;

use App\Models\NbDataPlan;
use Illuminate\Database\Eloquent\Collection;

class DataService extends NelloBytesClient
{
    /**
     * Get available data plans
     *
     * @return array
     *
     * @throws \App\Exceptions\NelloBytesApiException
     */
    public function getDataplan(): Collection
    {
        $dataplans = NbDataPlan::all();

        return $dataplans;
    }

    /**
     * Purchase data
     *
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

        if (! empty($callbackUrl)) {
            $params['CallBackURL'] = $callbackUrl;
        }

        return $this->makeRequest($endpoint, $params, 'POST');
    }
}
