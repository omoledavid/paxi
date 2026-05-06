<?php

namespace App\Console\Commands;

use App\Mail\AccountBanned;
use App\Mail\AdminSecurityAlert;
use App\Models\BlockedIp;
use App\Models\SecurityViolation;
use App\Models\User;
use App\Support\SecuritySettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SimulateSecurityEvent extends Command
{
    protected $signature = 'security:simulate
        {email : Email of the user to target (required for violation types; ignored for blocked_ip if --ip is given)}
        {--type=bot_burst : Event type — one of: blocked_ip, unverified, daily_limit, bot_burst, signup_ip}
        {--ip= : IP address to use (defaults to 192.0.2.<random>)}
        {--no-mail : Skip sending notification emails}';

    protected $description = 'Simulate a security event (block IP or record a security violation) so you can preview how it appears on the admin security-violations page.';

    public function handle(): int
    {
        $type = $this->option('type');
        $email = $this->argument('email');
        $ip = $this->option('ip') ?: '192.0.2.' . random_int(1, 254);
        $sendMail = ! $this->option('no-mail');

        $allowed = ['blocked_ip', 'unverified', 'daily_limit', 'bot_burst', 'signup_ip'];
        if (! in_array($type, $allowed, true)) {
            $this->error("Invalid --type. Allowed: " . implode(', ', $allowed));
            return self::INVALID;
        }

        $user = null;
        if ($email && $email !== '-') {
            $user = User::where('sEmail', $email)->first();
            if (! $user && $type !== 'blocked_ip' && $type !== 'signup_ip') {
                $this->error("No user found with email: {$email}");
                return self::FAILURE;
            }
        }

        $settings = SecuritySettings::get();

        switch ($type) {
            case 'blocked_ip':
                return $this->simulateBlockedIp($ip, $settings, $sendMail);

            case 'signup_ip':
                return $this->simulateSignupIp($ip, $settings, $sendMail);

            case 'unverified':
                return $this->simulateUnverified($user, $ip);

            case 'daily_limit':
                return $this->simulateDailyLimit($user, $ip, $settings);

            case 'bot_burst':
                return $this->simulateBotBurst($user, $ip, $settings, $sendMail);
        }

        return self::SUCCESS;
    }

    private function simulateBlockedIp(string $ip, $settings, bool $sendMail): int
    {
        $blockHours = (int) ($settings->signup_ip_block_hours ?? 5);
        // 0 = indefinite
        $until = $blockHours > 0 ? now()->addHours($blockHours) : null;

        BlockedIp::updateOrCreate(
            ['ip' => $ip],
            ['blocked_until' => $until, 'reason' => 'Manually simulated block', 'created_at' => now()]
        );

        $untilLabel = $until ? (string) $until : 'indefinitely';
        $this->info("✓ IP {$ip} blocked until {$untilLabel}");

        if ($sendMail) {
            $this->dispatchAdminAlert('IP auto-blocked (simulated)', [
                'ip' => $ip,
                'blocked_until' => $until?->toDateTimeString() ?? 'indefinite',
            ]);
        }

        return self::SUCCESS;
    }

    private function simulateSignupIp(string $ip, $settings, bool $sendMail): int
    {
        $max = (int) ($settings->signup_ip_max ?? 5);
        $windowHours = (int) ($settings->signup_ip_window_hours ?? 5);
        $blockHours = (int) ($settings->signup_ip_block_hours ?? 5);
        // 0 = indefinite
        $until = $blockHours > 0 ? now()->addHours($blockHours) : null;

        BlockedIp::updateOrCreate(
            ['ip' => $ip],
            ['blocked_until' => $until, 'reason' => 'Sign-up rate limit exceeded (simulated)', 'created_at' => now()]
        );

        SecurityViolation::create([
            'user_id' => null,
            'ip' => $ip,
            'type' => SecurityViolation::TYPE_SIGNUP_IP,
            'details' => [
                'attempts' => $max,
                'max' => $max,
                'window_hours' => $windowHours,
                'block_hours' => $blockHours === 0 ? 'indefinite' : $blockHours,
                'simulated' => true,
            ],
        ]);

        $untilLabel = $until ? (string) $until : 'indefinitely';
        $this->info("✓ Signup-IP violation logged for {$ip}; IP blocked {$untilLabel}");

        if ($sendMail) {
            $this->dispatchAdminAlert('IP auto-blocked (simulated sign-up rate limit)', [
                'ip' => $ip,
                'attempts' => $max,
                'window_hours' => $windowHours,
                'blocked_until' => $until?->toDateTimeString() ?? 'indefinite',
            ]);
        }

        return self::SUCCESS;
    }

    private function simulateUnverified(?User $user, string $ip): int
    {
        if (! $user) {
            $this->error('email is required for type=unverified');
            return self::FAILURE;
        }

        SecurityViolation::create([
            'user_id' => $user->sId,
            'ip' => $ip,
            'type' => SecurityViolation::TYPE_UNVERIFIED,
            'details' => [
                'kyc_status' => $user->kyc_status,
                'email_verified' => (bool) $user->email_verified_at,
                'mobile_verified' => (bool) $user->sMobileVerified,
                'route' => 'simulated/wallet-transfer',
                'simulated' => true,
            ],
        ]);

        $this->info("✓ Unverified-user violation logged for {$user->sEmail} (sId={$user->sId})");
        return self::SUCCESS;
    }

    private function simulateDailyLimit(?User $user, string $ip, $settings): int
    {
        if (! $user) {
            $this->error('email is required for type=daily_limit');
            return self::FAILURE;
        }

        $cap = (float) ($settings->daily_txn_limit_user ?? 100000);

        SecurityViolation::create([
            'user_id' => $user->sId,
            'ip' => $ip,
            'type' => SecurityViolation::TYPE_DAILY_LIMIT,
            'details' => [
                'amount' => $cap,
                'already_spent' => $cap,
                'cap' => $cap,
                'route' => 'simulated/wallet-transfer',
                'simulated' => true,
            ],
        ]);

        $this->info("✓ Daily-limit violation logged for {$user->sEmail} (cap: ₦" . number_format($cap, 2) . ')');
        return self::SUCCESS;
    }

    private function simulateBotBurst(?User $user, string $ip, $settings, bool $sendMail): int
    {
        if (! $user) {
            $this->error('email is required for type=bot_burst');
            return self::FAILURE;
        }

        $threshold = (int) ($settings->bot_txn_threshold ?? 5);
        $window = (int) ($settings->bot_txn_window_seconds ?? 120);

        $user->ban("High-frequency transactions: {$threshold} attempts in {$window}s (simulated)");

        SecurityViolation::create([
            'user_id' => $user->sId,
            'ip' => $ip,
            'type' => SecurityViolation::TYPE_BOT_BURST,
            'details' => [
                'attempts' => $threshold,
                'window_seconds' => $window,
                'threshold' => $threshold,
                'route' => 'simulated/wallet-transfer',
                'simulated' => true,
            ],
        ]);

        $this->info("✓ Bot-burst violation logged and user {$user->sEmail} banned");

        if ($sendMail) {
            try {
                if (! empty($user->sEmail)) {
                    Mail::to($user->sEmail)->queue(new AccountBanned($user));
                    $this->line("  → AccountBanned mail queued to {$user->sEmail}");
                }
            } catch (\Throwable $e) {
                $this->warn('  → Failed to queue user mail: ' . $e->getMessage());
            }

            $this->dispatchAdminAlert('Account auto-banned (simulated transaction burst)', [
                'user_id' => $user->sId,
                'email' => $user->sEmail,
                'ip' => $ip,
                'attempts' => $threshold,
                'window_seconds' => $window,
            ]);
        }

        return self::SUCCESS;
    }

    private function dispatchAdminAlert(string $subject, array $context): void
    {
        $adminEmail = config('mail.admin_address') ?? config('mail.from.address');
        if (! $adminEmail) {
            $this->warn('  → No admin email configured; skipping admin alert.');
            return;
        }
        try {
            Mail::to($adminEmail)->queue(new AdminSecurityAlert($subject, $context));
            $this->line("  → AdminSecurityAlert queued to {$adminEmail}");
        } catch (\Throwable $e) {
            $this->warn('  → Failed to queue admin mail: ' . $e->getMessage());
        }
    }
}
