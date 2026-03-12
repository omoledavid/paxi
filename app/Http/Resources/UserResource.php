<?php

namespace App\Http\Resources;

use App\Models\ReferralCommission;
use App\Models\User;
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
                'username' => $this->username,
                'email' => $this->sEmail,
                'phone_number' => $this->sPhone,
                'state' => $this->sState,
                'wallet_balance' => $this->sWallet,
                'referral_wallet_balance' => $this->sRefWallet,
                'apiKey' => $this->sApiKey,
                'email_verified' => $this->sRegStatus == 0,
                'mobile_verified' => $this->sMobileVerified ?? false,
                'verification_status' => ($this->sRegStatus == 0) && ($this->sMobileVerified ?? false),
                'referral_link' => env('FRONTEND_REF_URL').'/auth/sign-up?referral='.$this->username,
                'nin_status' => $this->nin_verified,
                'kyc_status' => $this->kyc_status,
                'banks' => [
                    [
                        'name' => 'Wema Bank',
                        'account_no' => $this->sBankNo,
                    ],
                    [
                        'name' => 'Rolex Bank',
                        'account_no' => $this->sRolexBank,
                    ],
                    [
                        'name' => 'Sterling Bank',
                        'account_no' => $this->sSterlingBank,
                    ],
                    [
                        'name' => 'Fidelity Bank',
                        'account_no' => $this->sFidelityBank,
                    ],
                ],
                'created_at' => $this->sRegDate,
                'referral_count' => $this->username ? User::where('sReferal', $this->username)->count() : 0,
                'referral_commissions' => $this->getReferralCommissions(),
            ],
        ];
    }

    private function getReferralCommissions(): array
    {
        $commission = ReferralCommission::forRole((int) $this->sType);

        // Fallback to User (role 0) if no record for this role
        if (! $commission) {
            $commission = ReferralCommission::forRole(0);
        }

        if (! $commission) {
            return [];
        }

        return [
            ['service' => 'Account Upgrade', 'bonus' => $commission->upgrade_bonus],
            ['service' => 'Airtime', 'bonus' => $commission->airtime_bonus],
            ['service' => 'Data', 'bonus' => $commission->data_bonus],
            ['service' => 'Wallet Funding', 'bonus' => $commission->wallet_bonus],
            ['service' => 'Cable TV', 'bonus' => $commission->cable_bonus],
            ['service' => 'Electricity', 'bonus' => $commission->meter_bonus],
            ['service' => 'Exam PIN', 'bonus' => $commission->exam_bonus],
            ['service' => 'Betting', 'bonus' => $commission->betting_bonus],
            ['service' => 'EPIN', 'bonus' => $commission->epin_bonus],
        ];
    }
}
