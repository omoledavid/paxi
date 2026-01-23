<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VtpassTransaction extends Model
{
    protected $table = 'vtpass_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'status' => TransactionStatus::class,
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the transaction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'sId');
    }

    public function scopeByStatus($query, TransactionStatus $status)
    {
        return $query->where('status', $status->value);
    }
}
