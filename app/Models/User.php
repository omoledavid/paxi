<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
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
            'is_banned' => 'boolean',
            'banned_at' => 'datetime',
            'pnd_active' => 'boolean',
            'can_transfer_to_bank' => 'boolean',
            'can_add_bank_account' => 'boolean',
            'device_otp_expires_at' => 'datetime',
            'device_otp_bypass_until' => 'datetime',
        ];
    }

    public function isVerifiedForTransactions(): bool
    {
        return $this->kyc_status === 'approved'
            && $this->sRegStatus == 0
            && (int) $this->sMobileVerified === 1;
    }

    public function dailyTransactionTotal(): float
    {
        return (float) Transaction::where('sId', $this->sId)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotIn('servicename', ['Wallet Topup', 'Wallet Funding', 'Wallet Credit', 'Referral Payout'])
            ->sum('amount');
    }

    public function ban(string $reason): void
    {
        $this->update([
            'is_banned' => 1,
            'banned_at' => now(),
            'banned_reason' => $reason,
        ]);
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
