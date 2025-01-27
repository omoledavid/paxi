<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ElectricityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'electricity',
            'id' => $this->eId,
            'attributes' => [
                'provider' => $this->provider,
                'abbreviation' => $this->abbreviation,
                'providerStatus' => $this->providerStatus,
            ]
        ];
    }
}
