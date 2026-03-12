<?php

use App\Models\ReferralCommission;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Ensure referral_commissions table has data for all 3 roles
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
                'airtime_bonus' => 1,
                'data_bonus' => 3,
                'wallet_bonus' => 1,
                'cable_bonus' => 5,
                'exam_bonus' => 10,
                'meter_bonus' => 5,
            ]
        );
    }
});

it('has referral commission records for all three roles', function () {
    $commissions = ReferralCommission::all();
    expect($commissions)->toHaveCount(3);

    $roles = $commissions->pluck('role')->sort()->values()->toArray();
    expect($roles)->toBe([0, 2, 3]);
});

it('can retrieve commission by role using forRole', function () {
    $userCommission = ReferralCommission::forRole(0);
    expect($userCommission)->not->toBeNull();
    expect($userCommission->role_name)->toBe('User');
    expect($userCommission->role)->toBe(0);

    $agentCommission = ReferralCommission::forRole(2);
    expect($agentCommission)->not->toBeNull();
    expect($agentCommission->role_name)->toBe('Agent');

    $vendorCommission = ReferralCommission::forRole(3);
    expect($vendorCommission)->not->toBeNull();
    expect($vendorCommission->role_name)->toBe('Vendor');
});

it('returns null for non-existent role', function () {
    $commission = ReferralCommission::forRole(99);
    expect($commission)->toBeNull();
});

it('can get bonus for a specific service type and role', function () {
    $bonus = ReferralCommission::getBonusForService(0, 'airtime');
    expect($bonus)->toBe(1.0);

    $bonus = ReferralCommission::getBonusForService(0, 'data');
    expect($bonus)->toBe(3.0);

    $bonus = ReferralCommission::getBonusForService(0, 'upgrade');
    expect($bonus)->toBe(5000.0);
});

it('returns 0 for non-existent role bonus', function () {
    $bonus = ReferralCommission::getBonusForService(99, 'airtime');
    expect($bonus)->toBe(0.0);
});

it('can update commission values for a role', function () {
    $commission = ReferralCommission::forRole(2); // Agent
    $commission->update([
        'airtime_bonus' => 2.5,
        'data_bonus' => 5.0,
    ]);

    $updated = ReferralCommission::forRole(2);
    expect($updated->airtime_bonus)->toBe(2.5);
    expect($updated->data_bonus)->toBe(5.0);
    // Other values should remain unchanged
    expect($updated->upgrade_bonus)->toBe(5000.0);
});

it('enforces unique role constraint', function () {
    // Attempting to insert a duplicate role should fail
    expect(fn () => ReferralCommission::create([
        'role' => 0,
        'role_name' => 'Duplicate User',
        'upgrade_bonus' => 0,
        'airtime_bonus' => 0,
        'data_bonus' => 0,
        'wallet_bonus' => 0,
        'cable_bonus' => 0,
        'exam_bonus' => 0,
        'meter_bonus' => 0,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('casts bonus values to float', function () {
    $commission = ReferralCommission::forRole(0);
    expect($commission->upgrade_bonus)->toBeFloat();
    expect($commission->airtime_bonus)->toBeFloat();
    expect($commission->data_bonus)->toBeFloat();
    expect($commission->wallet_bonus)->toBeFloat();
    expect($commission->cable_bonus)->toBeFloat();
    expect($commission->exam_bonus)->toBeFloat();
    expect($commission->meter_bonus)->toBeFloat();
});

it('allows different commission rates per role', function () {
    // Set different rates for each role
    ReferralCommission::where('role', 0)->update(['airtime_bonus' => 1.0]);
    ReferralCommission::where('role', 2)->update(['airtime_bonus' => 2.0]);
    ReferralCommission::where('role', 3)->update(['airtime_bonus' => 3.0]);

    expect(ReferralCommission::getBonusForService(0, 'airtime'))->toBe(1.0);
    expect(ReferralCommission::getBonusForService(2, 'airtime'))->toBe(2.0);
    expect(ReferralCommission::getBonusForService(3, 'airtime'))->toBe(3.0);
});
