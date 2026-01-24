<?php

namespace App\Services\VtuAfrica;

use App\Exceptions\VtuAfricaApiException;

class ElectricityService extends VtuAfricaClient
{
    /**
     * Verify electricity meter number
     *
     * @throws VtuAfricaApiException
     */
    public function verifyMeter(
        string $service,
        string $meterNo,
        string $meterType
    ): array {
        $endpoint = config('vtuafrica.endpoints.electricity.verify');

        $params = [
            'serviceName' => 'Electricity',
            'service' => strtolower($service),
            'meterNo' => $meterNo,
            'metertype' => strtolower($meterType),
        ];

        $response = $this->makeRequest($endpoint, $params, 'GET');

        $description = $response['description'] ?? [];

        return [
            'status' => 'success',
            'customer_name' => $description['Customer'] ?? null,
            'customer_no' => $description['customerNo'] ?? null,
            'service' => $description['Service'] ?? $service,
            'meter_number' => $description['MeterNumber'] ?? $meterNo,
            'meter_type' => $description['MeterType'] ?? $meterType,
            'address' => $description['Address'] ?? null,
            'variation_code' => $description['Variation_Code'] ?? null,
            'message' => $description['message'] ?? 'Verification Successful',
            'raw_response' => $response,
        ];
    }

    /**
     * Purchase electricity token/units
     *
     * @throws VtuAfricaApiException
     */
    public function purchaseElectricity(
        string $service,
        string $meterNo,
        string $meterType,
        float $amount,
        string $transactionRef
    ): array {
        $endpoint = config('vtuafrica.endpoints.electricity.purchase');

        $params = [
            'service' => strtolower($service),
            'meterNo' => $meterNo,
            'metertype' => strtolower($meterType),
            'amount' => $amount,
            'ref' => $transactionRef,
        ];

        $response = $this->makeRequest($endpoint, $params, 'GET');

        $description = $response['description'] ?? [];

        return [
            'status' => 'success',
            'product_name' => $description['ProductName'] ?? null,
            'meter_number' => $description['MeterNumber'] ?? $meterNo,
            'meter_type' => $description['MeterType'] ?? $meterType,
            'token' => $description['Token'] ?? null,
            'unit' => $description['Unit'] ?? null,
            'request_amount' => $description['Request_Amount'] ?? $amount,
            'amount_charged' => $description['Amount_Charged'] ?? null,
            'previous_balance' => $description['Previous_Balance'] ?? null,
            'current_balance' => $description['Current_Balance'] ?? null,
            'reference' => $description['ReferenceID'] ?? $transactionRef,
            'message' => $description['message'] ?? 'Recharge Successful',
            'raw_response' => $response,
        ];
    }

    /**
     * Map provider ID/abbreviation to VTU Africa service code
     */
    public static function mapServiceCode(string $providerId): ?string
    {
        $serviceMap = [
            // DB Abbreviation -> VTU Africa Service ID
            'IE' => 'ikeja-electric',
            'EKEDC' => 'eko-electric',
            'AEDC' => 'abuja-electric',
            'KEDCO' => 'kano-electric',
            'PHEDC' => 'portharcourt-electric',
            'JED' => 'jos-electric',
            'KEDC' => 'kaduna-electric',
            'ENUGU' => 'enugu-electric',
            'IBEDC' => 'ibadan-electric',
            'BENIN' => 'benin-electric',
            'ABA' => 'aba-electric',
            'YOLA' => 'yola-electric',
            // Lowercase variants
            'ikeja-electric' => 'ikeja-electric',
            'eko-electric' => 'eko-electric',
            'abuja-electric' => 'abuja-electric',
            'kano-electric' => 'kano-electric',
            'portharcourt-electric' => 'portharcourt-electric',
            'jos-electric' => 'jos-electric',
            'kaduna-electric' => 'kaduna-electric',
            'enugu-electric' => 'enugu-electric',
            'ibadan-electric' => 'ibadan-electric',
            'benin-electric' => 'benin-electric',
            'aba-electric' => 'aba-electric',
            'yola-electric' => 'yola-electric',
        ];

        return $serviceMap[$providerId] ?? strtolower($providerId);
    }
}
