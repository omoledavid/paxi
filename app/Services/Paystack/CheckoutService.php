<?php

namespace App\Services\Paystack;

class CheckoutService extends PaystackClient
{
    /**
     * Initialize a Paystack transaction and return authorization_url, access_code, and reference.
     *
     * @throws \App\Exceptions\PaystackApiException
     */
    public function initializeTransaction(array $params): array
    {
        return $this->makeRequest('transaction/initialize', $params, 'POST');
    }

    /**
     * Verify a Paystack transaction by reference.
     *
     * @throws \App\Exceptions\PaystackApiException
     */
    public function verifyTransaction(string $reference): array
    {
        return $this->makeRequest('transaction/verify/'.urlencode($reference), [], 'GET');
    }
}
