<?php

namespace App\Services\Paystack;

class BettingService extends PaystackClient
{
    public function getProviders(): array
    {
        return $this->makeRequest('bill/providers', ['service' => 'betting'], 'GET');
    }

    public function validateCustomer(string $provider, string $customerId): array
    {
        return $this->makeRequest('bill/validate', [
            'item_code' => $provider,
            'code' => $provider,
            'customer' => $customerId,
        ], 'POST');
    }

    public function fundWallet(string $provider, string $customerId, float $amount, ?string $email = null): array
    {
        return $this->makeRequest('payment', [
            'country' => 'NG',
            'customer' => $email ?? 'customer@paxi.com',
            'amount' => $amount * 100,
            // ...
        ], 'POST');
    }
}
