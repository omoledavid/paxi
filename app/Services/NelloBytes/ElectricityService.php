<?php 

namespace App\Services\NelloBytes;

class ElectricityService extends NelloBytesClient
{
    /**
     * Get electricity providers
     *
     * @return array
     * @throws \App\Exceptions\NelloBytesApiException
     */
    public function getProviders(): array
    {
        $endpoint = config('nellobytes.endpoints.electricity.providers');

        return $this->makeRequest($endpoint, [], 'GET');
    }
    /**
     * Purchase electricity
     *
     * @param string $providerCode
     * @param string $meterNumber
     * @param float $amount
     * @param string $transactionRef
     * @return array
     * @throws \App\Exceptions\NelloBytesApiException
     * @throws \App\Exceptions\NelloBytesInsufficientBalanceException
     */
    public function purchaseElectricity(
        string $providerCode,
        string $meterNumber,
        float $amount,
        string $transactionRef,
        ?string $callbackUrl = null
    ): array {
        $endpoint = config('nellobytes.endpoints.electricity.purchase');

        $payload = [
            'provider_code' => $providerCode,
            'meter_number' => $meterNumber,
            'amount' => $amount,
            'transaction_ref' => $transactionRef,
            'callback_url' => $callbackUrl,
        ];

        return $this->makeRequest($endpoint, $payload, 'POST');
    }
}