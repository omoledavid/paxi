<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Epin extends Model
{
    protected $guarded = ['id'];

    /**
     * Inventory bookkeeping that must never reach a buyer. purchase_cost in
     * particular discloses margin. Hidden explicitly rather than via $hidden so
     * the admin inventory endpoints keep seeing them.
     */
    public const INTERNAL_FIELDS = [
        'purchase_cost',
        'purchased_by_admin_id',
        'batch_reference',
        'source',
        'entry_method',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'purchase_cost' => 'decimal:2',
        'expiry_date' => 'datetime',
        'printed_at' => 'datetime',
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
