<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'transaction',
            'id' => $this->tId,
            'attributes' => [
                'transaction_ref' => $this->transref,
                'servicename' => $this->servicename,
                'servicedesc' => $this->servicedesc,
                'amount' => $this->amount,
                'status' => $this->status,
                'oldbal' => $this->oldbal,
                'newbal' => $this->newbal,
                'profit' => $this->profit,
                'date' => $this->date,
                'created_at' => $this->created_at?->toDateTimeString(),
                'updated_at' => $this->updated_at?->toDateTimeString(),
            ]
        ];
    }
}
