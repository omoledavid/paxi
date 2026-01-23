<?php

namespace App\Services\Paystack;

class ElectricityService extends PaystackClient
{
    public function getProviders(): array
    {
        return $this->makeRequest('bill/providers', ['service' => 'electricity'], 'GET');
    }

    public function verifyMeter(string $provider, string $meterNumber, string $meterType): array
    {
        return $this->makeRequest('bill/validate', [
            'item_code' => $provider,
            'code' => $provider,
            'customer' => $meterNumber,
            // 'type' => $meterType // paystack might not need type for validation if code implies it
        ], 'POST');
    }

    public function purchaseElectricity(string $provider, string $meterNumber, string $meterType, float $amount, string $phoneNo, ?string $email = null): array
    {
        return $this->makeRequest('payment', [
            'country' => 'NG',
            'customer' => $email ?? $phoneNo.'@paxi.com',
            'amount' => $amount * 100,
            // ... other paystack bill payment params
        ], 'POST');
    }
}
