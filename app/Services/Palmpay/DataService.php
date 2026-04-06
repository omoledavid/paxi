<?php

namespace App\Services\Palmpay;

use App\Exceptions\PalmpayApiException;
use App\Services\Palmpay\AirtimeService;

class DataService extends PalmpayClient
{
    /**
     * Purchase data via PalmPay Biller-Reseller API
     *
     * @param float|null $amount Amount in Naira (will be converted to kobo for API)
     *
     * @throws PalmpayApiException
     */
    public function purchaseData(
        string $service,
        string $phoneNumber,
        string $dataPlan,
        string $transactionRef,
        ?float $amount = null,
        ?string $notifyUrl = null
    ): array {
        $endpoint = config('palmpay.endpoints.create_order');

        $params = [
            'sceneCode' => 'data',
            'billerId' => strtoupper($service),
            'itemId' => $dataPlan,
            'rechargeAccount' => AirtimeService::toInternationalFormat($phoneNumber),
            'outOrderNo' => $transactionRef,
            'notifyUrl' => $notifyUrl ?: config('palmpay.notify_url', ''),
        ];

        if ($amount !== null) {
            $params['amount'] = (int) ($amount * 100); // Convert Naira to kobo
        }

        $response = $this->makeRequest($endpoint, $params, 'POST');

        $data = $response['data'] ?? [];

        return [
            'status' => 'success',
            'order_id' => $data['orderNo'] ?? null,
            'reference' => $data['outOrderNo'] ?? $transactionRef,
            'order_status' => $data['orderStatus'] ?? 0,
            'amount' => $amount,
            'phone_number' => $phoneNumber,
            'item_code' => $data['itemId'] ?? $dataPlan,
            'message' => $response['respMsg'] ?? 'Data purchase request submitted',
            'raw_response' => $response,
        ];
    }

    /**
     * Map network ID and data type to PalmPay billerId
     */
    public static function mapServiceCode(string $networkId, string $dataType = 'SME'): ?string
    {
        $networkMap = config('palmpay.network_map', [
            '1' => 'MTN',
            '2' => 'GLO',
            '3' => '9MOBILE',
            '4' => 'AIRTEL',
        ]);

        $network = $networkMap[$networkId] ?? null;

        if (!$network) {
            return null;
        }

        // PalmPay may use different prefixes for data types
        $typeMap = [
            'SME' => 'SME',
            'Gifting' => 'GIFTING',
            'Corporate' => 'CORPORATE',
        ];

        $type = $typeMap[$dataType] ?? 'SME';

        return $network . '_' . $type;
    }
    public function getPlans()
    {
        $endpoint = config('palmpay.endpoints.query_biller');
        $params = [
            'sceneCode' => 'betting',
        ];
        
        $response = $this->makeRequest($endpoint, $params, 'POST');
        
        return $response['data'] ?? [];
    }
}
