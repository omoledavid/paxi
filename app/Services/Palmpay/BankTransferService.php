<?php

namespace App\Services\Palmpay;

use App\Models\User;
use GuzzleHttp\Client;

class BankTransferService extends PalmpayClient
{
    public function __construct()
    {
        parent::__construct();

        // Payment API lives at /api/v2/payment/merchant/... not /api/v2/bill-payment/
        $paymentBaseUrl = rtrim(str_replace('bill-payment', '', $this->baseUrl), '/');

        $this->client = new Client([
            'base_uri' => $paymentBaseUrl . '/',
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
     * Create a bank transfer order.
     *
     * PalmPay returns a temporary virtual account that the user
     * transfers into via their banking app.
     *
     * @param  User   $user       The authenticated user
     * @param  float  $amountNaira Amount in naira
     * @param  string $orderId    Unique merchant order ID
     * @param  string $notifyUrl  Webhook URL for payment notification
     * @return array  Temporary account details + orderNo
     */
    public function createOrder(User $user, float $amountNaira, string $orderId, string $notifyUrl): array
    {
        $endpoint   = config('palmpay.endpoints.bank_transfer_create_order');
        $merchantId = getConfigValue($this->config, 'palmpayVirtualAccountMerchantId') ?: $this->appId;

        $params = [
            'merchantId'      => $merchantId,
            'orderId'         => $orderId,
            'amount'          => (int) ($amountNaira * 100), // Convert naira to kobo
            'currency'        => 'NGN',
            'notifyUrl'       => $notifyUrl,
            'productType'     => 'bank_transfer',
            'orderExpireTime' => 1800, // 30 minutes
        ];

        $response = $this->makeRequest($endpoint, $params, 'POST');
        $data = $response['data'] ?? [];

        return [
            'orderNo'           => $data['orderNo']           ?? '',
            'payerBankName'     => $data['payerBankName']     ?? '',
            'payerAccountName'  => $data['payerAccountName']  ?? '',
            'payerVirtualAccNo' => $data['payerVirtualAccNo'] ?? '',
            'expireTime'        => 1800,
        ];
    }
}
