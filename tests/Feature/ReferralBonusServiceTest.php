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
    expect($tx->status)->toBe(1);
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
