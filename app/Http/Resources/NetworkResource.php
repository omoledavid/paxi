<?php

namespace App\Http\Resources;

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
                'dataplans' => DataResource::collection($this->dataplans)
            ]
        ];
    }
}
