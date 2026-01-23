<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $table = 'subscribers';

    protected $guarded = ['sId'];

    protected $primaryKey = 'sId';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'sPin' => 'integer',
            'sVerCodeExpiry' => 'datetime',
            'sMobileVerCodeExpiry' => 'datetime',
            'sMobileVerified' => 'boolean',
            'sEmailVerificationAttempts' => 'integer',
            'sEmailVerificationAttemptsResetAt' => 'datetime',
            'sPasswordResetAttempts' => 'integer',
            'sPasswordResetAttemptsResetAt' => 'datetime',
            'failed_login_attempts' => 'integer',
            'locked_at' => 'datetime',
            'locked_until' => 'datetime',
            'kyc_approved_at' => 'datetime',
        ];
    }

    public function kycAttempts()
    {
        return $this->hasMany(KycAttempt::class, 'user_id', 'sId');
    }

    public function incrementFailedAttempts(): int
    {
        $this->increment('failed_login_attempts');
        $this->refresh();

        return $this->failed_login_attempts;
    }

    public function lockAccount(): void
    {
        $this->update([
            'locked_at' => now(),
            'locked_until' => now()->addMinutes(30),
        ]);
    }

    public function unlockAccount(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_at' => null,
            'locked_until' => null,
        ]);
    }

    public function isLocked(): bool
    {
        if (! $this->locked_until) {
            return false;
        }

        if ($this->locked_until->isPast()) {
            $this->unlockAccount();

            return false;
        }

        return true;
    }

    public function getKey()
    {
        return $this->sId; // Use custom_id as the tokenable_id
    }
}
