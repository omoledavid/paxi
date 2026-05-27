<?php

namespace App\Http\Resources;

use App\Models\ElectricityDiscount;
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
        $user = auth()->user();
        $role = $user ? (int) $user->sType : 0;
        $providerId = (int) $this->eId;

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
            'id' => $this->eId,
            'attributes' => [
                'provider' => $this->provider,
                'abbreviation' => $this->abbreviation,
                'providerStatus' => $this->providerStatus,
                'discount' => $discountInfo,
            ],
        ];
    }
}
