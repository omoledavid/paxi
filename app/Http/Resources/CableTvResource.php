<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CableTvResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'tv_cable_provider',
            'id' => $this->cId,
            'attributes' => [
                'name' => $this->provider,
                'providerStatus' => $this->providerStatus,
            ],
            'relationships' => [
                'plan' => CableTVPlanResource::collection(
                    $this->resource instanceof \Illuminate\Database\Eloquent\Model
                    ? $this->whenLoaded('plans')
                    : $this->plans
                ),
            ],
        ];
    }
}
