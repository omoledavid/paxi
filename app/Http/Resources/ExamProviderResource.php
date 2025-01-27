<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'exam_provider',
            'id' => $this->eId,
            'attributes' => [
                'name' => $this->provider,
                'price' => $this->price,
                'providerStatus' => $this->providerStatus,
            ]
        ];
    }
}
