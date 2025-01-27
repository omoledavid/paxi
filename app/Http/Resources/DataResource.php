<?php

namespace App\Http\Resources;

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
        return [
            'type' => 'data',
            'id' => $this->pId,
            'attributes' => [
                'name' => $this->name,
                'price' => $this->userprice,
                'type' => $this->type,
                'day' => $this->day,
                'network' => $this->network->network
            ]
        ];
    }
}
