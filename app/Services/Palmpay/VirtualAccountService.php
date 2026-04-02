<?php

namespace App\Services\Palmpay;

use App\Models\User;
use GuzzleHttp\Client;

class VirtualAccountService extends PalmpayClient
{
    public function __construct()
    {
        parent::__construct();

        // The VA API lives at /api/v2/virtual/... not /api/v2/bill-payment/
        // Strip the bill-payment segment from the parent's base URL.
        $vaBaseUrl = rtrim(str_replace('bill-payment', '', $this->baseUrl), '/');

        $this->client = new Client([
            'base_uri' => $vaBaseUrl . '/',
            'timeout'  => $this->timeout,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $this->appId,
                'CountryCode'   => 'NG',
            ],
        ]);
    }

    public function createVirtualAccount(User $user, array $extra = []): array
    {
        $endpoint    = config('palmpay.endpoints.virtual_account_create');
        $merchantId  = getConfigValue($this->config, 'palmpayVirtualAccountMerchantId') ?: $this->appId;
        $customerName = trim($user->sFname . ' ' . $user->sLname);

        $params = [
            'merchantId'         => $merchantId,
            'merchantUserId'     => (string) $user->sId,
            'customerName'       => $customerName,
            'virtualAccountName' => $extra['virtualAccountName'] ?? $customerName,
            'identityType'       => $extra['identityType']       ?? 'personal',
            'licenseNumber'      => $extra['licenseNumber']      ?? '',
            'userPhone'          => $user->sPhone,
            'userEmail'          => $user->sEmail,
        ];

        $response = $this->makeRequest($endpoint, $params, 'POST');
        $data = $response['data'] ?? [];

        return [
            'bankName'          => $data['bankName']           ?? $data['bank_name']      ?? 'PalmPay',
            'accountNumber'     => $data['virtualAccountNo']   ?? $data['accountNumber']  ?? $data['account_number'] ?? '',
            'accountHolderName' => $data['virtualAccountName'] ?? $data['customerName']   ?? '',
        ];
    }
}
