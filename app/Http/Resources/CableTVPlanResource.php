<?php

namespace App\Http\Resources;

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
        return [
            'type' => 'cable_tv_plans',
            'id' => $this->cpId,
            'attributes' => [
                'name' => $this->name,
                'price' => $this->userprice,
                'day' => $this->day,
            ],
        ];
    }
}
