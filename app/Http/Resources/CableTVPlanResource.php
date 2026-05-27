<?php

namespace App\Http\Resources;

use App\Models\CableDiscount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CableTVPlanResource extends JsonResource
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
        $providerId = $this->cableprovider ?? $this->cableProvider ?? null;

        // Get base price (userprice is the reference/base price in cableplans table)
        $basePrice = (float) ($this->userprice ?? $this->price ?? 0);
        $originalPrice = $basePrice;
        $discountRate = 0; // Discount percentage (e.g., 5 = 5% off)
        $calculatedPrice = $basePrice;

        // Calculate discount if provider ID is available
        if ($providerId) {
            $dbDiscount = CableDiscount::forProvider((int) $providerId);
            if ($dbDiscount) {
                $discountRate = match ($role) {
                    0 => $dbDiscount->userDiscount,
                    1 => $dbDiscount->userDiscount,
                    2 => $dbDiscount->agentDiscount,
                    3 => $dbDiscount->vendorDiscount,
                    default => 0,
                };
                $calculatedPrice = CableDiscount::calculatePrice($basePrice, (int) $providerId, $role);
            }
        }

        $discountApplied = $discountRate > 0;

        return [
            'type' => 'cable_tv_plans',
            'id' => $this->cpId,
            'attributes' => [
                'name' => $this->name,
                'price' => (string) $calculatedPrice,
                'original_price' => (string) $originalPrice,
                'discount_rate' => $discountRate,
                'discount_applied' => $discountApplied,
                'day' => $this->day,
            ],
        ];
    }
}
