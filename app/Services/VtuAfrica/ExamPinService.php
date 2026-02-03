<?php

namespace App\Services\VtuAfrica;

use App\Exceptions\VtuAfricaApiException;

class ExamPinService extends VtuAfricaClient
{
    /**
     * Purchase exam PIN via VTU Africa API
     *
     * @throws VtuAfricaApiException
     */
    public function purchaseExamPin(
        string $service,
        string $productCode,
        int $quantity,
        string $transactionRef,
        array $optionalParams = []
    ): array {
        $endpoint = config('vtuafrica.endpoints.exam.purchase', 'exam-pin/');

        $params = [
            'service' => $service,
            'product_code' => $productCode,
            'quantity' => $quantity,
            'ref' => $transactionRef,
        ];

        // Add optional parameters if present (for JAMB etc)
        if (!empty($optionalParams)) {
            $params = array_merge($params, $optionalParams);
        }

        // The endpoint usually supports both GET and POST. Using POST for better practice.
        // However, the documentation shows query parameters even for POST in some examples.
        // The base class makeRequest puts params in 'query'.
        $response = $this->makeRequest($endpoint, $params, 'POST');

        $description = $response['description'] ?? [];

        return [
            'status' => 'success',
            'product_name' => $description['ProductName'] ?? null,
            'quantity' => $description['Quantity'] ?? $quantity,
            'pins' => $description['pins'] ?? null, // This might contain the actual PIN(s)
            'amount_charged' => $description['Amount_Charged'] ?? null,
            'previous_balance' => $description['Previous_Balance'] ?? null,
            'current_balance' => $description['Current_Balance'] ?? null,
            'reference' => $description['ReferenceID'] ?? $transactionRef,
            'message' => $description['message'] ?? 'Purchase Successful',
            'raw_response' => $response,
        ];
    }
}
