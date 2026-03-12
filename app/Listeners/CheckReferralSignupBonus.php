<?php

namespace App\Listeners;

use App\Events\KycApproved;
use App\Services\ReferralBonusService;

class CheckReferralSignupBonus
{
    /**
     * Handle the KycApproved event.
     * When a user's KYC is approved, check if the referral signup bonus conditions are met.
     */
    public function handle(KycApproved $event): void
    {
        $user = $event->user;

        ReferralBonusService::checkAndCreditSignupBonus($user);
    }
}
