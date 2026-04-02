<?php

namespace App\Services\Palmpay;

use App\Exceptions\PalmpayApiException;

class AirtimeService extends PalmpayClient
{
    /**
     * Purchase airtime via PalmPay Biller-Reseller API
     *
     * @param float $amount Amount in Naira (will be converted to kobo for API)
     *
     * @throws PalmpayApiException
     */
    public function purchaseAirtime(
        string $network,
        string $phoneNumber,
        float $amount,
        string $transactionRef,
        ?string $notifyUrl = null
    ): array {
        $endpoint = config('palmpay.endpoints.create_order');
        $billerId = strtoupper($network);

        $params = [
            'sceneCode'      => 'airtime',
            'billerId'       => $billerId,
            'itemId'         => $this->getAirtimeItemId($billerId),
            'amount'         => (int) ($amount * 100), // Convert Naira to kobo
            'rechargeAccount' => self::toInternationalFormat($phoneNumber),
            'outOrderNo'     => $transactionRef,
            'notifyUrl'      => $notifyUrl ?: config('palmpay.notify_url', ''),
        ];

        $response = $this->makeRequest($endpoint, $params, 'POST');

        $data = $response['data'] ?? [];

        return [
            'status'       => 'success',
            'order_id'     => $data['orderNo'] ?? null,
            'reference'    => $data['outOrderNo'] ?? $transactionRef,
            'order_status' => $data['orderStatus'] ?? 0,
            'amount'       => $amount,
            'phone_number' => $phoneNumber,
            'message'      => $response['respMsg'] ?? 'Airtime purchase request submitted',
            'raw_response' => $response,
        ];
    }

    /**
     * Fetch and cache the correct itemId for airtime from PalmPay item/query API.
     *
     * For airtime PalmPay returns a single "Adaptable Amount" item (isFixAmount=0)
     * per network. We cache the result per billerId for 24 hours.
     *
     * @throws PalmpayApiException
     */
    public function getAirtimeItemId(string $billerId): string
    {
        $cacheKey = 'palmpay:airtime:item:' . strtolower($billerId);

        return $this->remember($cacheKey, function () use ($billerId) {
            $response = $this->queryItem('airtime', $billerId);
            $items = $response['data'] ?? [];

            // Prefer the adaptable (variable) amount item (isFixAmount == 0)
            foreach ($items as $item) {
                if (isset($item['status']) && $item['status'] == 1
                    && isset($item['isFixAmount']) && $item['isFixAmount'] == 0) {
                    return $item['itemId'];
                }
            }

            // Fallback: return the first available item
            foreach ($items as $item) {
                if (isset($item['status']) && $item['status'] == 1) {
                    return $item['itemId'];
                }
            }

            throw new PalmpayApiException(
                "No available airtime item found for billerId: {$billerId}",
                'ITEM_NOT_FOUND',
                $items,
                500
            );
        });
    }

    /**
     * Convert Nigerian phone number to PalmPay's required format: 0234 + local number.
     *
     * PalmPay expects the leading 0 to be kept:
     *   08062765353  → 023408062765353  (prepend 0234, keep leading 0)
     *   2348062765353 → 023408062765353  (convert international back to PalmPay format)
     *   023408062765353 → 023408062765353 (already correct, unchanged)
     */
    public static function toInternationalFormat(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone); // strip non-digits

        // Already in PalmPay format (0234XXXXXXXXXXX, 15 digits)
        if (str_starts_with($phone, '0234')) {
            return $phone;
        }

        // International format without leading 0 (2348XXXXXXXXX) → prepend 0
        if (str_starts_with($phone, '234')) {
            return '0' . $phone;
        }

        // Local format (08XXXXXXXXX) → prepend 0234
        if (str_starts_with($phone, '0')) {
            return '0234' . $phone;
        }

        // Bare number (8XXXXXXXXX) → prepend 02340
        return '02340' . $phone;
    }

    /**
     * Map network ID to PalmPay network code (billerId)
     */
    public static function mapNetworkCode(string $networkId): ?string
    {
        $networkMap = config('palmpay.network_map', [
            '1' => 'MTN',
            '2' => 'GLO',
            '3' => '9MOBILE',
            '4' => 'AIRTEL',
        ]);

        return $networkMap[$networkId] ?? null;
    }
}
