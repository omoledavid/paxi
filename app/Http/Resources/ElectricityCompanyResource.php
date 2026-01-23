<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ElectricityCompanyResource extends JsonResource
{
    public function toArray($request): array
    {
        // $this->resource is now the actual provider array: ['ID' => '03', 'NAME' => '...', 'PRODUCT' => ...]
        return [
            'type' => 'electricity',
            'id' => $this->resource['ID'],
            'attributes' => [
                'provider' => $this->resource['NAME'],
                'abbreviation' => $this->resource['NAME'], // or extract abbreviation if needed
                'providerStatus' => 'On',
            ],
        ];
    }
}
