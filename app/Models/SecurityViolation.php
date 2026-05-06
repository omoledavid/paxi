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
}
