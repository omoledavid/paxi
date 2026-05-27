<?php

namespace App\Http\Resources;

use App\Models\ElectricityDiscount;
use Illuminate\Http\Resources\Json\JsonResource;

class ElectricityCompanyResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = auth()->user();
        $role = $user ? (int) $user->sType : 0;
        // Use provider_id if available (from controller mapping), otherwise fall back to ID
        $providerId = (int) ($this->resource['provider_id'] ?? $this->resource['ID'] ?? 0);

        // Get discount info for this provider
        $discount = ElectricityDiscount::forProvider($providerId);
        $discountInfo = null;

        if ($discount) {
            $discountValue = $discount->getDiscountForRole($role);
            $discountInfo = [
                'type' => $discount->discount_type,
                'value' => $discountValue,
                'min_amount' => $discount->min_amount,
                'max_amount' => $discount->max_amount,
                'has_discount' => $discountValue > 0,
            ];
        }

        return [
            'type' => 'electricity',
            'id' => $this->resource['ID'],
            'attributes' => [
                'provider' => $this->resource['NAME'],
                'abbreviation' => $this->resource['NAME'],
                'providerStatus' => 'On',
                'discount' => $discountInfo,
            ],
        ];
    }
}
