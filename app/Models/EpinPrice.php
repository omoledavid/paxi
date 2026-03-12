<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EpinPrice extends Model
{
    protected $table = 'epin_prices';

    protected $fillable = [
        'network_id',
        'network_name',
        'amount',
        'user_price',
        'agent_price',
        'vendor_price',
    ];

    protected $casts = [
        'amount' => 'integer',
        'user_price' => 'float',
        'agent_price' => 'float',
        'vendor_price' => 'float',
    ];

    /**
     * Get all prices for a specific network.
     */
    public static function forNetwork(string $networkId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('network_id', $networkId)->orderBy('amount')->get();
    }

    /**
     * Get the payable price for a specific network, amount, and user role.
     *
     * @param  int  $role  0=User, 2=Agent, 3=Vendor
     */
    public static function getPayablePrice(string $networkId, int $amount, int $role): float
    {
        $record = static::where('network_id', $networkId)
            ->where('amount', $amount)
            ->first();

        if (! $record) {
            return (float) $amount; // fallback to face value
        }

        return match ($role) {
            2 => $record->agent_price,
            3 => $record->vendor_price,
            default => $record->user_price,
        };
    }

    /**
     * Get the discount rate for a network/amount/role combination.
     * Returns multiplier (e.g. 0.96 means 4% discount).
     */
    public static function getDiscountRate(string $networkId, int $amount, int $role): float
    {
        $payable = static::getPayablePrice($networkId, $amount, $role);

        return $amount > 0 ? $payable / $amount : 1.0;
    }
}
