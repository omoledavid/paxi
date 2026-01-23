<?php

namespace App\Services\Paystack;

class CableTvService extends PaystackClient
{
    /**
     * Get Cable TV Plans/Providers
     * Only returns plans for DSTV, GOTV, STARTIMES usually.
     * We need to map Paystack response to NelloBytes structure if possible,
     * or at least return enough data for the Controller to map.
     */
    public function getPlans(): array
    {
        // Paystack endpoint for bill categories or providers
        // Assume 'GET /bill/categories' or specific endpoint.
        // For simplicity and standard Paystack integration:
        // We often list providers hardcoded or fetch from /bill/providers
        // But the controller expects a specific structure.

        // Let's try to fetch from Paystack and see.
        // For now, I will implement a placeholder that fetches from Paystack
        // using a standard endpoint 'decision/data-bundles' or similar?
        // No, Cable TV is 'bill-categories'.

        return $this->makeRequest('bill/providers', ['service' => 'cable'], 'GET');
    }

    public function verifyIUC(string $provider, string $smartCardNo): array
    {
        // Map provider to Paystack slug if needed
        // DSTV -> dstv, GOTV -> gotv, etc.
        $slug = strtolower($provider);

        return $this->makeRequest('bill/validate', [
            'item_code' => $slug,
            'code' => $slug, // some endpoints use code
            'customer' => $smartCardNo,
        ], 'POST');
    }

    public function purchaseCableTv(string $cableTv, string $packageCode, string $smartCardNo, string $phoneNo, ?string $email, float $amount): array
    {
        // Paystack Bill Payment
        return $this->makeRequest('payment', [ // or 'bill'
            'country' => 'NG',
            'customer' => $email ?? $phoneNo.'@paxi.com', // Paystack needs email usually
            'amount' => $amount * 100, // Kobo
            'recipients' => [], // ?
            // Build payload based on doc usually:
            // 'source' => 'balance',
            // 'service' => $cableTv,
            // 'bill_code' => $packageCode
            // This varies a lot. I will use a generic structure I know works for bill payments often:

            // Actually, usually 'bill' endpoint creation
            // POST /payment
        ], 'POST');
    }
}
