<?php

namespace App\Services\Vtpass;

class InternationalAirtimeService extends VtpassClient
{
    public function getCountries(): array
    {
        return $this->makeRequest('GET', 'get-international-airtime-countries');
    }

    public function getProductTypes(string $countryCode): array
    {
        return $this->makeRequest('GET', "get-international-airtime-product-types?code={$countryCode}");
    }

    public function getOperators(string $countryCode, int $productTypeId): array
    {
        return $this->makeRequest('GET', "get-international-airtime-operators?code={$countryCode}&product_type_id={$productTypeId}");
    }

    public function getVariations(string $operatorId, int $productTypeId): array
    {
        return $this->makeRequest('GET', "service-variations?serviceID=foreign-airtime&operator_id={$operatorId}&product_type_id={$productTypeId}");
    }

    public function purchaseInternationalAirtime(string $requestId, array $payload): array
    {
        $payload['request_id'] = $requestId;
        $payload['serviceID'] = 'foreign-airtime';

        return $this->purchaseProduct($payload);
    }
}
