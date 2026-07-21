<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Epin extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'purchase_cost' => 'decimal:2',
        'expiry_date' => 'datetime',
        'disbursed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'sId');
    }

    public function transaction()
    {
        return $this->belongsTo(NelloBytesTransaction::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'purchased_by_admin_id', 'sId');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('user_id')->where('status', 'unused');
    }

    public function scopeByNetwork(Builder $query, string $network): Builder
    {
        return $query->where('network', $network);
    }

    public function scopeBySource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    public function scopeAdminBulk(Builder $query): Builder
    {
        return $query->where('source', 'admin_bulk');
    }

    public function scopeDisbursed(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    public function scopeByBatch(Builder $query, string $batchRef): Builder
    {
        return $query->where('batch_reference', $batchRef);
    }
}
