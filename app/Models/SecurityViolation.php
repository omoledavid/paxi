<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityViolation extends Model
{
    protected $table = 'security_violations';

    public $timestamps = false;

    protected $fillable = ['user_id', 'ip', 'type', 'details', 'created_at'];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public const TYPE_UNVERIFIED = 'unverified';
    public const TYPE_DAILY_LIMIT = 'daily_limit';
    public const TYPE_BOT_BURST = 'bot_burst';
    public const TYPE_SIGNUP_IP = 'signup_ip';
    public const TYPE_PND_ACTIVATED = 'pnd_activated';
    public const TYPE_PND_DEACTIVATED = 'pnd_deactivated';
    public const TYPE_REF_WITHDRAWAL_LIMIT = 'ref_withdrawal_limit';
}
