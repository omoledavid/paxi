<?php

namespace App\Models;

use App\Enums\PaystackServiceType;
use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaystackTransaction extends Model
{
    protected $table = 'paystack_transactions';

    protected $guarded = ['id'];

    protected $casts = [
        'service_type' => PaystackServiceType::class,
        'status' => TransactionStatus::class, // Assuming TransactionStatus enum is compatible or string
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'user_id' => 'integer',
    ];

    /**
     * Get the user that owns the transaction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'sId');
    }

    // Scopes mirroring NelloBytesTransaction
    public function scopeByServiceType($query, PaystackServiceType $serviceType)
    {
        return $query->where('service_type', $serviceType->value);
    }

    public function scopeByStatus($query, $status)
    {
        // Handle Enum or String status
        $val = $status instanceof \BackedEnum ? $status->value : $status;

        return $query->where('status', $val);
    }
}
