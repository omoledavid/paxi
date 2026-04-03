<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataDiscount extends Model
{
    protected $table = 'datadiscount';
    protected $primaryKey = 'dId';

    protected $fillable = [
        'dNetwork',
        'dBuyDiscount',
        'dUserDiscount',
        'dAgentDiscount',
        'dVendorDiscount',
    ];

    protected $casts = [
        'dBuyDiscount' => 'float',
        'dUserDiscount' => 'float',
        'dAgentDiscount' => 'float',
        'dVendorDiscount' => 'float',
    ];

    public static function forNetwork(int $networkId): ?self
    {
        return static::where('dNetwork', $networkId)->first();
    }
}
