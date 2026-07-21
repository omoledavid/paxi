<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CronJobLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_name',
        'status',
        'started_at',
        'finished_at',
        'users_checked',
        'users_credited',
        'error_message',
        'triggered_by',
        'ip_address',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_MANUAL = 'manual';

    public function scopeForJob($query, string $jobName)
    {
        return $query->where('job_name', $jobName);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', self::STATUS_RUNNING);
    }
}
