<?php

namespace App\Services\Palmpay;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PayoutService extends PalmpayClient
{
    public function __construct()
    {
        parent::__construct();

        // Payout APIs live under /api/v2/general/... and /api/v2/payment/...
        // Strip the "bill-payment/" segment so we can address both paths.
        $payoutBaseUrl = rtrim(str_replace('bill-payment', '', $this->baseUrl), '/');

        $this->client = new Client([
            'base_uri' => $payoutBaseUrl . '/',
            'timeout'  => $this->timeout,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->appId,
                'CountryCode'   => 'NG',
            ],
        ]);
    }

    /**
     * Override the version used for payout API requests.
     * Payout APIs use V1.1 instead of V2.
     */
    protected function makePayoutRequest(string $endpoint, array $params = []): array
    {
        // Override the version injected by parent::makeRequest
        $params['version'] = 'V1.1';

        return $this->makeRequest($endpoint, $params, 'POST');
    }

    /**
     * Query the list of supported banks for payouts.
     *
     * Cached for 24 hours since the bank list rarely changes.
     *
     * @return array List of banks, each with bankCode, bankName, bankUrl, bgUrl
     */
    public function queryBankList(): array
    {
        $cacheKey = 'palmpay_bank_list';
        $cacheTtl = config('palmpay.cache.ttl', 86400);

        return Cache::remember($cacheKey, $cacheTtl, function () {
            $endpoint = config('palmpay.endpoints.payout_query_bank_list');

            $response = $this->makePayoutRequest($endpoint, [
                'businessType' => 0,
            ]);

            $data = $response['data'] ?? [];

            // The API may return a single object or an array — normalise to array
            if (isset($data['bankCode'])) {
                $data = [$data];
            }

            Log::info('PayoutService: bank list fetched', [
                'count' => count($data),
            ]);

            return $data;
        });
    }

    /**
     * Initiate a payout (withdrawal) to a bank account.
     *
     * @param  string $orderId       Unique merchant order ID
     * @param  string $payeeName     Account holder name
     * @param  string $bankCode      PalmPay bank code
     * @param  string $accountNumber Bank account number
     * @param  int    $amountKobo    Amount in kobo (₦100 = 10000)
     * @param  string $notifyUrl     Webhook URL for payout result
     * @param  string $remark        Optional remark
     * @return array  Parsed response with orderNo, orderId, orderStatus, fee
     */
    public function payout(
        string $orderId,
        string $payeeName,
        string $bankCode,
        string $accountNumber,
        int $amountKobo,
        string $notifyUrl,
        string $remark = ''
    ): array {
        $endpoint = config('palmpay.endpoints.payout');

        $response = $this->makePayoutRequest($endpoint, [
            'orderId'         => $orderId,
            'payeeName'       => $payeeName,
            'payeeBankCode'   => $bankCode,
            'payeeBankAccNo'  => $accountNumber,
            'amount'          => $amountKobo,
            'currency'        => 'NGN',
            'notifyUrl'       => $notifyUrl,
            'remark'          => $remark,
        ]);

        $data = $response['data'] ?? [];

        Log::info('PayoutService: payout initiated', [
            'orderId'     => $orderId,
            'orderStatus' => $data['orderStatus'] ?? null,
            'orderNo'     => $data['orderNo'] ?? null,
        ]);

        return [
            'orderNo'     => $data['orderNo'] ?? '',
            'orderId'     => $data['orderId'] ?? $orderId,
            'orderStatus' => (int) ($data['orderStatus'] ?? 0),
            'fee'         => $data['fee']['fee'] ?? 0,
            'amount'      => $data['amount'] ?? $amountKobo,
            'sessionId'   => $data['sessionId'] ?? '',
        ];
    }

    /**
     * Query a bank account to verify the account holder name.
     *
     * @param  string $bankCode      PalmPay bank code (e.g. "100031")
     * @param  string $accountNumber Bank account number
     * @return array  { accountName: string }
     */
    public function queryBankAccount(string $bankCode, string $accountNumber): array
    {
        $endpoint = config('palmpay.endpoints.payout_query_bank_account');

        $response = $this->makePayoutRequest($endpoint, [
            'bankCode'  => $bankCode,
            'bankAccNo' => $accountNumber,
        ]);

        $data = $response['data'] ?? [];

        return [
            'account_name' => $data['accountName'] ?? '',
        ];
    }
}
