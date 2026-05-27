<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectricityDiscount extends Model
{
    protected $table = 'electricity_discounts';

    protected $fillable = [
        'provider_id',
        'discount_type',
        'discount_value',
        'min_amount',
        'max_amount',
        'buy_discount',
        'user_discount',
        'agent_discount',
        'vendor_discount',
    ];

    protected $casts = [
        'discount_type' => 'string',
        'discount_value' => 'float',
        'min_amount' => 'float',
        'max_amount' => 'float',
        'buy_discount' => 'float',
        'user_discount' => 'float',
        'agent_discount' => 'float',
        'vendor_discount' => 'float',
    ];

    public static function forProvider(int $providerId): ?self
    {
        return static::where('provider_id', $providerId)->first();
    }

    public static function getDiscount(int $providerId, int $role): ?self
    {
        return static::where('provider_id', $providerId)->first();
    }

    public function getDiscountForRole(int $role): float
    {
        return match ($role) {
            0 => $this->user_discount,
            1 => $this->user_discount,
            2 => $this->agent_discount,
            3 => $this->vendor_discount,
            default => 0,
        };
    }

    public static function calculatePayableAmount(float $amount, int $providerId, int $role): array
    {
        $discount = static::forProvider($providerId);
        
        if (!$discount) {
            return [
                'original_amount' => $amount,
                'payable_amount' => $amount,
                'discount_amount' => 0,
                'discount_percentage' => 0,
                'discount_applied' => false,
            ];
        }

        // Check min/max thresholds
        if (($discount->min_amount !== null && $amount < $discount->min_amount) ||
            ($discount->max_amount !== null && $amount > $discount->max_amount)) {
            return [
                'original_amount' => $amount,
                'payable_amount' => $amount,
                'discount_amount' => 0,
                'discount_percentage' => 0,
                'discount_applied' => false,
            ];
        }

        $discountValue = $discount->getDiscountForRole($role);
        $discountAmount = 0;

        if ($discount->discount_type === 'percentage') {
            $discountAmount = round(($amount * $discountValue) / 100, 2);
        } else {
            $discountAmount = $discountValue;
        }

        $payableAmount = max(0, $amount - $discountAmount);
        $discountPercentage = $discount->discount_type === 'percentage' ? $discountValue : round(($discountAmount / $amount) * 100, 2);

        return [
            'original_amount' => $amount,
            'payable_amount' => $payableAmount,
            'discount_amount' => $discountAmount,
            'discount_percentage' => $discountPercentage,
            'discount_applied' => $discountAmount > 0,
        ];
    }
}
