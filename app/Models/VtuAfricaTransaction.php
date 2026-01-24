<?php

namespace App\Models;

use App\Enums\VtuAfricaServiceType;
use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VtuAfricaTransaction extends Model
{
    protected $table = 'vtuafrica_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'service_type' => VtuAfricaServiceType::class,
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

    /**
     * Scope a query to only include transactions of a specific service type
     */
    public function scopeByServiceType($query, VtuAfricaServiceType $serviceType)
    {
        return $query->where('service_type', $serviceType->value);
    }

    /**
     * Scope a query to only include transactions with a specific status
     */
    public function scopeByStatus($query, TransactionStatus $status)
    {
        return $query->where('status', $status->value);
    }

    /**
     * Scope a query to only include transactions for a specific user
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include pending transactions
     */
    public function scopePending($query)
    {
        return $query->where('status', TransactionStatus::PENDING->value);
    }

    /**
     * Scope a query to only include successful transactions
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', TransactionStatus::SUCCESS->value);
    }

    /**
     * Scope a query to only include failed transactions
     */
    public function scopeFailed($query)
    {
        return $query->where('status', TransactionStatus::FAILED->value);
    }
}
