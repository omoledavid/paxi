<?php

namespace App\Services\NelloBytes;

class ElectricityService extends NelloBytesClient
{
    /**
     * Get electricity providers
     *
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
     * @throws \App\Exceptions\NelloBytesApiException
     * @throws \App\Exceptions\NelloBytesInsufficientBalanceException
     */
    public function purchaseElectricity(
        string $providerCode,
        string $meterType,
        string $meterNumber,
        float $amount,
        string $phoneNumber,
        string $transactionRef,
        ?string $callbackUrl = null
    ): array {
        $endpoint = config('nellobytes.endpoints.electricity.buy');

        $payload = [
            'ElectricCompany' => $providerCode,
            'MeterType' => $meterType,
            'MeterNo' => $meterNumber,
            'Amount' => $amount,
            'PhoneNo' => $phoneNumber,
            'callback_url' => $callbackUrl,
        ];

        return $this->makeRequest($endpoint, $payload, 'POST');
    }

    public function VeryMeterNumber(string $providerCode, string $meterNumber, string $meterType): array
    {
        $endpoint = config('nellobytes.endpoints.electricity.verify');

        $payload = [
            'ElectricCompany' => $providerCode,
            'MeterNo' => $meterNumber,
            'MeterType' => $meterType,
        ];

        return $this->makeRequest($endpoint, $payload, 'POST');
    }

    public function getElectricityProviders(): array
    {
        $endpoint = config('nellobytes.endpoints.electricity.providers');

        return $this->makeRequest($endpoint, [], 'GET');
    }
}
