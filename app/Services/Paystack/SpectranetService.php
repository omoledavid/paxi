<?php

namespace App\Services\Paystack;

class SpectranetService extends PaystackClient
{
    public function getPackages(): array
    {
        // Paystack spectranet might be under 'internet' bill category
        return $this->makeRequest('bill/providers', ['service' => 'internet'], 'GET');
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
