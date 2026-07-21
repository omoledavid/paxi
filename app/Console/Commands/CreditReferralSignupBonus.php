<?php

namespace App\Console\Commands;

use App\Models\CronJobLog;
use App\Models\User;
use App\Services\ReferralBonusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreditReferralSignupBonus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:signup-bonus
                            {--triggered-by=cron : Who triggered the run (cron or manual)}
                            {--ip= : IP address of the requester for manual runs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Credit one-time referral signup bonuses to eligible referrers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $jobName = 'referral_signup_bonus';
        $triggeredBy = in_array($this->option('triggered-by'), [CronJobLog::TRIGGER_CRON, CronJobLog::TRIGGER_MANUAL], true)
            ? $this->option('triggered-by')
            : CronJobLog::TRIGGER_CRON;
        $ipAddress = $this->option('ip') ?: null;

        // Prevent overlapping runs.
        $alreadyRunning = CronJobLog::forJob($jobName)
            ->where('status', CronJobLog::STATUS_RUNNING)
            ->where('started_at', '>=', now()->subHours(2))
            ->exists();

        if ($alreadyRunning) {
            $this->warn('Another referral signup bonus run is already in progress.');
            Log::info('Referral signup bonus run skipped: another run is already in progress');
            return self::SUCCESS;
        }

        $log = CronJobLog::create([
            'job_name' => $jobName,
            'status' => CronJobLog::STATUS_RUNNING,
            'started_at' => now(),
            'triggered_by' => $triggeredBy,
            'ip_address' => $ipAddress,
        ]);

        $usersChecked = 0;
        $usersCredited = 0;
        $errorMessage = null;

        try {
            // Pre-filter likely candidates so we don't scan the whole table.
            $query = User::query()
                ->whereNotNull('sReferal')
                ->where('sReferal', '!=', '')
                ->where('kyc_status', 'approved')
                ->where('referral_bonus_credited', 0);

            $query->chunkById(100, function ($users) use (&$usersChecked, &$usersCredited) {
                foreach ($users as $user) {
                    $usersChecked++;

                    $result = ReferralBonusService::checkAndCreditSignupBonus($user);

                    if ($result !== null && ($result['type'] ?? null) === 'signup_bonus') {
                        $usersCredited++;
                    }
                }
            }, 'sId', 'sId');

            $log->update([
                'status' => CronJobLog::STATUS_SUCCESS,
                'finished_at' => now(),
                'users_checked' => $usersChecked,
                'users_credited' => $usersCredited,
            ]);

            $this->info(sprintf(
                'Referral signup bonus run completed. Checked: %d, credited: %d.',
                $usersChecked,
                $usersCredited
            ));

            Log::info('Referral signup bonus run completed', [
                'users_checked' => $usersChecked,
                'users_credited' => $usersCredited,
                'triggered_by' => $log->triggered_by,
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();

            $log->update([
                'status' => CronJobLog::STATUS_FAILED,
                'finished_at' => now(),
                'users_checked' => $usersChecked,
                'users_credited' => $usersCredited,
                'error_message' => $errorMessage,
            ]);

            Log::error('Referral signup bonus run failed', [
                'users_checked' => $usersChecked,
                'users_credited' => $usersCredited,
                'error' => $errorMessage,
                'triggered_by' => $log->triggered_by,
            ]);

            $this->error('Referral signup bonus run failed: ' . $errorMessage);

            return self::FAILURE;
        }
    }
}
