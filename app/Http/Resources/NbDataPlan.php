<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NbDataPlan extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'DataPlan',
            'id' => $this?->pId,
            'attributes' => [
                'name' => $this->name,
                'plan_code' => $this->plan_code,
                'price' => $this->userprice,
                'type' => $this->type,
                'validity' => $this->day.' days',
                'datanetwork' => $this->datanetwork ? networkName($this->datanetwork) : null,
                'network_code' => $this->datanetwork,
                'data_size' => $this->datasize,
            ],
        ];
    }
}
