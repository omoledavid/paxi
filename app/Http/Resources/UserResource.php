<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'user',
            'id' => $this->sId,
            'attributes' => [
                'apikey' => $this->sApiKey,
                'firstname' => $this->sFname,
                'lastname' => $this->sLname,
                'email' => $this->sEmail,
                'phone_number' => $this->sPhone,
                'state' => $this->sState,
                'wallet_balance' => $this->sWallet,
                'referral_wallet_balance' => $this->sRefWallet,
                'apiKey' => $this->sApiKey,
                'verification_status' => ($this->sRegSatus === 3) ? 'unverified' : 'verified',
                'referral_link' => env('FRONTEND_URL').'/mobile/register/?referral='.$this->sPhone,
                'banks' => [
                    [
                        'name' => 'Rolex Bank',
                        'account_no' => $this->sRolexBank
                    ],
                    [
                        'name' => 'Sterling Bank',
                        'account_no' => $this->sSterlingBank
                    ],
                    [
                        'name' => 'Fidelity Bank',
                        'account_no' => $this->sFidelityBank
                    ],
                ],
                'created_at' => $this->sRegDate,
            ]
        ];
    }
}
