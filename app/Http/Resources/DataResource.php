<?php

namespace App\Http\Resources;

use App\Models\DataDiscount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sType = $request->attributes->get('user_stype', 0);

        $discount = DataDiscount::forNetwork((int) $this->datanetwork);

        $discountRate = match ($sType) {
            1 => $discount->dUserDiscount ?? 100,
            2 => $discount->dAgentDiscount ?? 100,
            3 => $discount->dVendorDiscount ?? 100,
            default => 100,
        };

        $discountedPrice = round(($this->userprice / 100) * $discountRate, 2);

        return [
            'type' => 'data',
            'id' => $this->pId,
            'attributes' => [
                'name' => $this->name,
                'price' => $this->userprice,
                'discounted_price' => $discountedPrice,
                'discount_percentage' => $discountRate < 100 ? round(100 - $discountRate, 2) : 0,
                'type' => $this->type,
                'day' => $this->day,
                'network' => $this->network->network,
            ],
        ];
    }
}
