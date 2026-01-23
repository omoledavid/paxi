<?php

namespace App\Services\Paystack;

class SmileService extends PaystackClient
{
    public function getPackages(): array
    {
        return $this->makeRequest('bill/providers', ['service' => 'smile-direct'], 'GET');
    }

    public function verifyCustomer(string $customerId): array
    {
        return $this->makeRequest('bill/validate', [
            'item_code' => 'smile-direct',
            'code' => 'smile-direct',
            'customer' => $customerId,
        ], 'POST');
    }

    public function buyBundle(string $planCode, string $customerId, float $amount, string $phoneNo, ?string $email = null): array
    {
        return $this->makeRequest('payment', [
            'country' => 'NG',
            'customer' => $email ?? $phoneNo.'@paxi.com',
            'amount' => $amount * 100,
            // ...
        ], 'POST');
    }
}
