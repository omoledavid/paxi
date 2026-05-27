<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CableDiscount extends Model
{
    protected $table = 'cablediscount';
    protected $primaryKey = 'cdId';

    protected $fillable = [
        'cableProvider',
        'buyDiscount',
        'userDiscount',
        'agentDiscount',
        'vendorDiscount',
    ];

    protected $casts = [
        'buyDiscount' => 'float',
        'userDiscount' => 'float',
        'agentDiscount' => 'float',
        'vendorDiscount' => 'float',
    ];

    public static function forProvider(int $providerId): ?self
    {
        return static::where('cableProvider', $providerId)->first();
    }

    public static function getDiscountRate(int $providerId, int $role): float
    {
        $discount = static::forProvider($providerId);
        
        if (!$discount) {
            return 100;
        }

        return match ($role) {
            0 => $discount->userDiscount,
            1 => $discount->userDiscount,
            2 => $discount->agentDiscount,
            3 => $discount->vendorDiscount,
            default => 100,
        };
    }

    public static function calculatePrice(float $basePrice, int $providerId, int $role): float
    {
        $discountRate = static::getDiscountRate($providerId, $role);
        // Apply discount as percentage off total price (e.g., 5% discount = pay 95% of price)
        $discountPercentage = min(max($discountRate, 0), 100);
        return round($basePrice * ((100 - $discountPercentage) / 100), 2);
    }
}
