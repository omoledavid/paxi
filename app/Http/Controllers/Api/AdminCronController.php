<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CronJobLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdminCronController extends Controller
{
    /**
     * Run the referral signup bonus cron job manually from the admin panel.
     *
     * Protected by a shared secret stored in .env / config/services.php so only
     * the legacy admin panel can trigger it.
     */
    public function runReferralSignupBonus(Request $request): JsonResponse
    {
        $expectedSecret = config('services.admin_cron_secret');

        if (empty($expectedSecret)) {
            Log::error('Admin cron endpoint called but ADMIN_CRON_SECRET is not configured');
            return response()->json([
                'success' => false,
                'message' => 'Cron secret not configured on the server.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $providedSecret = (string) $request->header('X-Admin-Cron-Secret');
        if (empty($providedSecret)) {
            $auth = $request->header('Authorization', '');
            if (str_starts_with($auth, 'Bearer ')) {
                $providedSecret = substr($auth, 7);
            }
        }

        if (! hash_equals($expectedSecret, $providedSecret)) {
            Log::warning('Unauthorized admin cron attempt', [
                'ip' => $request->ip(),
                'job' => 'referral_signup_bonus',
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Check for an already-running job before invoking the command again.
        $alreadyRunning = CronJobLog::forJob('referral_signup_bonus')
            ->where('status', CronJobLog::STATUS_RUNNING)
            ->where('started_at', '>=', now()->subHours(2))
            ->exists();

        if ($alreadyRunning) {
            return response()->json([
                'success' => true,
                'message' => 'Another run is already in progress.',
                'users_checked' => 0,
                'users_credited' => 0,
                'status' => CronJobLog::STATUS_RUNNING,
            ]);
        }

        try {
            $ip = $request->ip();

            // On Unix-like servers, spawn the command in the background so the HTTP
            // request returns immediately and avoids PHP/web-server timeouts. This
            // is especially important for the first run which may have a large backlog.
            if (PHP_OS_FAMILY !== 'Windows' && function_exists('exec')) {
                $phpBinary = PHP_BINARY ?: 'php';
                $artisanPath = base_path('artisan');
                $command = sprintf(
                    'nohup %s %s referral:signup-bonus --triggered-by=%s --ip=%s > /dev/null 2>&1 &',
                    escapeshellarg($phpBinary),
                    escapeshellarg($artisanPath),
                    escapeshellarg(CronJobLog::TRIGGER_MANUAL),
                    escapeshellarg($ip ?: '127.0.0.1')
                );

                exec($command);

                Log::info('Manual referral signup bonus cron started in background', [
                    'ip' => $ip,
                    'command' => $command,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Cron job started in the background. Refresh the Developer page in a minute to see the result.',
                    'users_checked' => 0,
                    'users_credited' => 0,
                    'status' => CronJobLog::STATUS_RUNNING,
                ]);
            }

            // Fallback: run synchronously on Windows or when exec is disabled.
            $exitCode = Artisan::call('referral:signup-bonus', [
                '--triggered-by' => CronJobLog::TRIGGER_MANUAL,
                '--ip' => $ip,
            ]);

            $output = Artisan::output();
            $log = CronJobLog::forJob('referral_signup_bonus')
                ->latest('started_at')
                ->first();

            return response()->json([
                'success' => $exitCode === 0,
                'message' => trim($output) ?: 'Run completed.',
                'users_checked' => $log?->users_checked ?? 0,
                'users_credited' => $log?->users_credited ?? 0,
                'status' => $log?->status ?? CronJobLog::STATUS_FAILED,
                'error_message' => $log?->error_message,
            ]);
        } catch (\Throwable $e) {
            Log::error('Manual referral signup bonus cron failed', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to run cron job: ' . $e->getMessage(),
                'users_checked' => 0,
                'users_credited' => 0,
                'status' => CronJobLog::STATUS_FAILED,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
