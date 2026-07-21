<?php

namespace App\Http\Resources;

use App\Models\Airtime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NetworkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'network',
            'id' => $this->nId,
            'attributes' => [
                'network' => $this->network,
                'network_status' => $this->networkStatus,
                'vtuStatus' => $this->vtuStatus,
                'sharesellStatus' => $this->sharesellStatus,
                'airtimepinStatus' => $this->airtimepinStatus,
                'smeStatus' => $this->smeStatus,
                'giftingStatus' => $this->giftingStatus,
                'corporateStatus' => $this->corporateStatus,
                'datapinStatus' => $this->datapinStatus,
                'dataplans' => DataResource::collection($this->whenLoaded('dataPlans')),
                'discount' => $this->getAirtimeDiscount($request),
            ],
        ];
    }

    /**
     * Get airtime discount information for the authenticated user.
     *
     * @return array<string, mixed>|null
     */
    private function getAirtimeDiscount(Request $request): ?array
    {
        $user = $request->user();

        if (!$user) {
            return null;
        }

        // Get airtime discount for this network (VTU type)
        $airtimeDiscount = Airtime::where('aNetwork', $this->nId)
            ->where('aType', 'VTU')
            ->first();

        if (!$airtimeDiscount) {
            return [
                'type' => 'percentage',
                'value' => 0,
                'min_amount' => null,
                'max_amount' => null,
                'has_discount' => false,
            ];
        }

        // Get discount rate based on user type (stored as percentage, e.g., 99 = 99%)
        $discountRate = match ((int) $user->sType) {
            1 => $airtimeDiscount->aUserDiscount ?? 100,
            2 => $airtimeDiscount->aAgentDiscount ?? 100,
            3 => $airtimeDiscount->aVendorDiscount ?? 100,
            default => 100,
        };

        // Calculate discount percentage (e.g., 99% rate = 1% discount)
        $discountPercentage = 100 - $discountRate;

        return [
            'type' => 'percentage',
            'value' => $discountPercentage,
            'min_amount' => null,
            'max_amount' => null,
            'has_discount' => $discountPercentage > 0,
        ];
    }
}
