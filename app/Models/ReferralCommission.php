<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralCommission extends Model
{
    protected $table = 'referral_commissions';

    protected $fillable = [
        'role',
        'role_name',
        'upgrade_bonus',
        'airtime_bonus',
        'data_bonus',
        'wallet_bonus',
        'cable_bonus',
        'exam_bonus',
        'meter_bonus',
        'betting_bonus',
        'epin_bonus',
        'referral_signup_bonus',
        'min_transaction_amount',
    ];

    protected $casts = [
        'role' => 'integer',
        'upgrade_bonus' => 'float',
        'airtime_bonus' => 'float',
        'data_bonus' => 'float',
        'wallet_bonus' => 'float',
        'cable_bonus' => 'float',
        'exam_bonus' => 'float',
        'meter_bonus' => 'float',
        'betting_bonus' => 'float',
        'epin_bonus' => 'float',
        'referral_signup_bonus' => 'float',
        'min_transaction_amount' => 'float',
    ];

    /**
     * Get commission settings for a specific role.
     */
    public static function forRole(int $role): ?self
    {
        return static::where('role', $role)->first();
    }

    /**
     * Get the bonus amount for a specific service type and role.
     */
    public static function getBonusForService(int $role, string $serviceType): float
    {
        $commission = static::forRole($role);

        if (! $commission) {
            return 0;
        }

        $column = $serviceType.'_bonus';

        return $commission->$column ?? 0;
    }
}
