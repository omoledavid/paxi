<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignupIpAttempt extends Model
{
    protected $table = 'signup_ip_attempts';

    public $timestamps = false;

    protected $fillable = ['ip', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
