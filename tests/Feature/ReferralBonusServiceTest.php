<?php

use App\Models\ReferralCommission;
use App\Models\User;
use App\Services\ReferralBonusService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Seed referral commissions for all roles
    foreach ([0, 2, 3] as $role) {
        $roleName = match ($role) {
            0 => 'User',
            2 => 'Agent',
            3 => 'Vendor',
            default => 'Unknown',
        };

        ReferralCommission::updateOrCreate(
            ['role' => $role],
            [
                'role_name' => $roleName,
                'upgrade_bonus' => 5000,
                'airtime_bonus' => 2,
                'data_bonus' => 3,
                'wallet_bonus' => 1,
                'cable_bonus' => 5,
                'exam_bonus' => 10,
                'meter_bonus' => 5,
                'referral_signup_bonus' => 0, // Default to 0, tests will override as needed
                'min_transaction_amount' => 0,
            ]
        );
    }

    // Create a referrer
    $this->referrer = User::factory()->create([
        'username' => 'ref' . Str::random(5),
        'sType' => 0,
        'sWallet' => 10000,
        'sRefWallet' => 0,
    ]);

    // Create a referred user who has the referrer
    $this->user = User::factory()->create([
        'sReferal' => $this->referrer->username,
        'sType' => 0,
        'sWallet' => 5000,
    ]);
});

it('credits referral bonus to referrer on airtime transaction', function () {
    $result = ReferralBonusService::credit(
        $this->user,
        1000,
        ReferralBonusService::AIRTIME,
        'TX-TEST-001'
    );

    expect($result)->not->toBeNull();
    expect($result['bonus_percentage'])->toBe(2.0);
    expect($result['bonus_amount'])->toBe(20.0); // 2% of 1000
    expect($result['service_type'])->toBe('airtime');

    // Verify referrer's sRefWallet was credited
    $this->referrer->refresh();
    expect((float) $this->referrer->sRefWallet)->toBe(20.0);
});

it('credits referral bonus to referrer on data transaction', function () {
    $result = ReferralBonusService::credit(
        $this->user,
        500,
        ReferralBonusService::DATA,
        'TX-TEST-002'
    );

    expect($result)->not->toBeNull();
    expect($result['bonus_percentage'])->toBe(3.0);
    expect($result['bonus_amount'])->toBe(15.0); // 3% of 500
});

it('credits referral bonus to referrer on cable transaction', function () {
    $result = ReferralBonusService::credit(
        $this->user,
        2000,
        ReferralBonusService::CABLE,
        'TX-TEST-003'
    );

    expect($result)->not->toBeNull();
    expect($result['bonus_percentage'])->toBe(5.0);
    expect($result['bonus_amount'])->toBe(100.0); // 5% of 2000
});

it('credits referral bonus to referrer on meter transaction', function () {
    $result = ReferralBonusService::credit(
        $this->user,
        3000,
        ReferralBonusService::METER,
        'TX-TEST-004'
    );

    expect($result)->not->toBeNull();
    expect($result['bonus_percentage'])->toBe(5.0);
    expect($result['bonus_amount'])->toBe(150.0); // 5% of 3000
});

it('credits referral bonus to referrer on exam transaction', function () {
    $result = ReferralBonusService::credit(
        $this->user,
        1500,
        ReferralBonusService::EXAM,
        'TX-TEST-005'
    );

    expect($result)->not->toBeNull();
    expect($result['bonus_percentage'])->toBe(10.0);
    expect($result['bonus_amount'])->toBe(150.0); // 10% of 1500
});

it('credits referral bonus to referrer on wallet transaction', function () {
    $result = ReferralBonusService::credit(
        $this->user,
        5000,
        ReferralBonusService::WALLET,
        'TX-TEST-006'
    );

    expect($result)->not->toBeNull();
    expect($result['bonus_percentage'])->toBe(1.0);
    expect($result['bonus_amount'])->toBe(50.0); // 1% of 5000
});

it('returns null when user has no referrer', function () {
    $userNoRef = User::factory()->create([
        'sReferal' => null,
        'sWallet' => 5000,
    ]);

    $result = ReferralBonusService::credit(
        $userNoRef,
        1000,
        ReferralBonusService::AIRTIME,
        'TX-TEST-007'
    );

    expect($result)->toBeNull();
});

it('returns null when referrer username does not exist', function () {
    $userBadRef = User::factory()->create([
        'sReferal' => 'nouser123',
        'sWallet' => 5000,
    ]);

    $result = ReferralBonusService::credit(
        $userBadRef,
        1000,
        ReferralBonusService::AIRTIME,
        'TX-TEST-008'
    );

    expect($result)->toBeNull();
});

it('uses referrer role to determine bonus percentage', function () {
    // Upgrade referrer to Agent (role 2)
    $this->referrer->update(['sType' => 2]);

    // Agent airtime bonus is also 2% in our seed, but let's set it differently
    ReferralCommission::where('role', 2)->update(['airtime_bonus' => 4]);

    $result = ReferralBonusService::credit(
        $this->user,
        1000,
        ReferralBonusService::AIRTIME,
        'TX-TEST-009'
    );

    expect($result)->not->toBeNull();
    expect($result['bonus_percentage'])->toBe(4.0);
    expect($result['bonus_amount'])->toBe(40.0); // 4% of 1000
});

it('logs referral bonus transaction in transactions table', function () {
    ReferralBonusService::credit(
        $this->user,
        1000,
        ReferralBonusService::AIRTIME,
        'TX-TEST-010'
    );

    $tx = DB::table('transactions')
        ->where('sId', $this->referrer->sId)
        ->where('servicename', 'Referral Bonus')
        ->first();

    expect($tx)->not->toBeNull();
    expect((float) $tx->amount)->toBe(20.0);
    expect($tx->status)->toBe(0); // 0 = success
    expect($tx->transref)->toStartWith('REF-');
});

it('returns null when bonus percentage is zero', function () {
    ReferralCommission::where('role', 0)->update(['airtime_bonus' => 0]);

    $result = ReferralBonusService::credit(
        $this->user,
        1000,
        ReferralBonusService::AIRTIME,
        'TX-TEST-011'
    );

    expect($result)->toBeNull();
});

it('does not affect referrer main wallet (sWallet)', function () {
    $originalWallet = (float) $this->referrer->sWallet;

    ReferralBonusService::credit(
        $this->user,
        1000,
        ReferralBonusService::AIRTIME,
        'TX-TEST-012'
    );

    $this->referrer->refresh();
    expect((float) $this->referrer->sWallet)->toBe($originalWallet);
});

it('accumulates referral bonus across multiple transactions', function () {
    ReferralBonusService::credit($this->user, 1000, ReferralBonusService::AIRTIME, 'TX-A');
    ReferralBonusService::credit($this->user, 2000, ReferralBonusService::DATA, 'TX-B');

    $this->referrer->refresh();
    // airtime: 2% of 1000 = 20, data: 3% of 2000 = 60
    expect((float) $this->referrer->sRefWallet)->toBe(80.0);
});

it('prevents duplicate credit for same transaction reference', function () {
    $txRef = 'TX-DUPLICATE-TEST';
    
    // First credit should succeed
    $result1 = ReferralBonusService::credit(
        $this->user,
        1000,
        ReferralBonusService::AIRTIME,
        $txRef
    );
    
    expect($result1)->not->toBeNull();
    expect($result1['bonus_amount'])->toBe(20.0);
    
    $this->referrer->refresh();
    expect((float) $this->referrer->sRefWallet)->toBe(20.0);
    
    // Second credit with same transaction reference should be prevented
    $result2 = ReferralBonusService::credit(
        $this->user,
        1000,
        ReferralBonusService::AIRTIME,
        $txRef
    );
    
    expect($result2)->toBeNull();
    
    // Wallet should still be 20.0, not 40.0
    $this->referrer->refresh();
    expect((float) $this->referrer->sRefWallet)->toBe(20.0);
    
    // Verify only one transaction was logged
    $txCount = DB::table('transactions')
        ->where('transref', 'REF-' . $txRef)
        ->where('servicename', 'Referral Bonus')
        ->count();
    
    expect($txCount)->toBe(1);
});

it('allows different transaction references to credit separately', function () {
    // Credit with first transaction reference
    ReferralBonusService::credit($this->user, 1000, ReferralBonusService::AIRTIME, 'TX-001');
    
    // Credit with second transaction reference
    ReferralBonusService::credit($this->user, 1000, ReferralBonusService::AIRTIME, 'TX-002');
    
    $this->referrer->refresh();
    // Both should be credited: 20 + 20 = 40
    expect((float) $this->referrer->sRefWallet)->toBe(40.0);
    
    // Verify two separate transactions were logged
    $txCount = DB::table('transactions')
        ->where('sId', $this->referrer->sId)
        ->where('servicename', 'Referral Bonus')
        ->count();
    
    expect($txCount)->toBe(2);
});

it('credits signup bonus when conditions are met', function () {
    // Setup commission with signup bonus
    ReferralCommission::where('role', 0)->update([
        'referral_signup_bonus' => 500,
        'min_transaction_amount' => 1000,
    ]);
    
    // Approve KYC for the user
    $this->user->update(['kyc_status' => 'approved']);
    
    // Create some successful transactions for the user
    DB::table('transactions')->insert([
        'sId' => $this->user->sId,
        'transref' => 'TX-USER-001',
        'servicename' => 'Airtime',
        'servicedesc' => 'Airtime purchase',
        'amount' => 800,
        'status' => 0, // success
        'oldbal' => 5000,
        'newbal' => 4200,
        'profit' => 0,
        'date' => now(),
        'created_at' => now(),
    ]);
    
    DB::table('transactions')->insert([
        'sId' => $this->user->sId,
        'transref' => 'TX-USER-002',
        'servicename' => 'Data',
        'servicedesc' => 'Data purchase',
        'amount' => 500,
        'status' => 0, // success
        'oldbal' => 4200,
        'newbal' => 3700,
        'profit' => 0,
        'date' => now(),
        'created_at' => now(),
    ]);
    
    $result = ReferralBonusService::checkAndCreditSignupBonus($this->user);
    
    expect($result)->not->toBeNull();
    expect($result['bonus_amount'])->toBe(500.0);
    expect($result['type'])->toBe('signup_bonus');
    
    // Verify referrer's wallet was credited
    $this->referrer->refresh();
    expect((float) $this->referrer->sRefWallet)->toBe(500.0);
    
    // Verify transaction was logged with correct status
    $tx = DB::table('transactions')
        ->where('sId', $this->referrer->sId)
        ->where('servicename', 'Referral Signup Bonus')
        ->first();
    
    expect($tx)->not->toBeNull();
    expect($tx->status)->toBe(0); // 0 = success
    expect((float) $tx->amount)->toBe(500.0);
    
    // Verify user is marked as having received signup bonus
    $this->user->refresh();
    expect((int) $this->user->referral_bonus_credited)->toBe(1);
});

it('prevents duplicate signup bonus credit', function () {
    // Setup commission with signup bonus
    ReferralCommission::where('role', 0)->update([
        'referral_signup_bonus' => 500,
        'min_transaction_amount' => 0, // No minimum required
    ]);
    
    // Approve KYC for the user
    $this->user->update(['kyc_status' => 'approved']);
    
    // First call should succeed
    $result1 = ReferralBonusService::checkAndCreditSignupBonus($this->user);
    expect($result1)->not->toBeNull();
    expect($result1['bonus_amount'])->toBe(500.0);
    
    $this->referrer->refresh();
    expect((float) $this->referrer->sRefWallet)->toBe(500.0);
    
    // Second call should be prevented
    $result2 = ReferralBonusService::checkAndCreditSignupBonus($this->user);
    expect($result2)->toBeNull();
    
    // Wallet should still be 500.0, not 1000.0
    $this->referrer->refresh();
    expect((float) $this->referrer->sRefWallet)->toBe(500.0);
    
    // Verify only one signup bonus transaction was logged
    $txCount = DB::table('transactions')
        ->where('sId', $this->referrer->sId)
        ->where('servicename', 'Referral Signup Bonus')
        ->count();
    
    expect($txCount)->toBe(1);
});

it('does not credit signup bonus when KYC not approved', function () {
    // Setup commission with signup bonus
    ReferralCommission::where('role', 0)->update([
        'referral_signup_bonus' => 500,
        'min_transaction_amount' => 0,
    ]);
    
    // Explicitly set KYC to not approved
    $this->user->update(['kyc_status' => 'pending']);
    $result = ReferralBonusService::checkAndCreditSignupBonus($this->user);
    
    expect($result)->toBeNull();
    
    // Verify no wallet credit occurred
    $this->referrer->refresh();
    expect((float) $this->referrer->sRefWallet)->toBe(0.0);
});

it('does not credit signup bonus when minimum transactions not met', function () {
    // Setup commission with signup bonus and minimum amount
    ReferralCommission::where('role', 0)->update([
        'referral_signup_bonus' => 500,
        'min_transaction_amount' => 2000,
    ]);
    
    // Approve KYC
    $this->user->update(['kyc_status' => 'approved']);
    
    // Create transactions totaling less than minimum
    DB::table('transactions')->insert([
        'sId' => $this->user->sId,
        'transref' => 'TX-USER-001',
        'servicename' => 'Airtime',
        'servicedesc' => 'Airtime purchase',
        'amount' => 500,
        'status' => 0, // success
        'oldbal' => 5000,
        'newbal' => 4500,
        'profit' => 0,
        'date' => now(),
        'created_at' => now(),
    ]);
    
    $result = ReferralBonusService::checkAndCreditSignupBonus($this->user);
    
    expect($result)->toBeNull();
    
    // Verify no wallet credit occurred
    $this->referrer->refresh();
    expect((float) $this->referrer->sRefWallet)->toBe(0.0);
});
