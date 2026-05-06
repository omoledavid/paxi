<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockedIp extends Model
{
    protected $table = 'blocked_ips';

    public $timestamps = false;

    protected $fillable = ['ip', 'blocked_until', 'reason', 'created_at'];

    protected $casts = [
        'blocked_until' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function isActivelyBlocked(): bool
    {
        // null = indefinite block
        return $this->blocked_until === null || $this->blocked_until->isFuture();
    }
}
