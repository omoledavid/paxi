<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'job_id',
        'status',
        'product_type',
        'template_id',
        'nin',
        'face_match',
        'nin_match',
        'liveness_score',
        'confidence_value',
        'result_json',
        'rejection_reason',
    ];

    protected $casts = [
        'face_match' => 'boolean',
        'nin_match' => 'boolean',
        'liveness_score' => 'float',
        'confidence_value' => 'float',
        'result_json' => 'array',
        'template_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'sId');
    }
}
