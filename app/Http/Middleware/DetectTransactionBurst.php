<?php

namespace App\Http\Middleware;

use App\Mail\AccountBanned;
use App\Mail\AdminSecurityAlert;
use App\Models\SecurityViolation;
use App\Support\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class DetectTransactionBurst
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ((int) $user->is_banned === 1) {
            return response()->json([
                'message' => 'Your account has been suspended due to suspicious activity. Please contact support.',
            ], 423);
        }

        $settings = SecuritySettings::get();
        $threshold = (int) ($settings->bot_txn_threshold ?? 5);
        $window = (int) ($settings->bot_txn_window_seconds ?? 120);

        if ($threshold <= 0 || $window <= 0) {
            return $next($request);
        }

        $key = 'txn_burst:' . $user->sId;
        $now = now()->timestamp;
        $cutoff = $now - $window;

        $timestamps = Cache::get($key, []);
        $timestamps = array_values(array_filter($timestamps, fn ($t) => $t >= $cutoff));
        $timestamps[] = $now;
        Cache::put($key, $timestamps, $window);

        if (count($timestamps) >= $threshold) {
            $user->ban('High-frequency transactions: ' . count($timestamps) . ' attempts in ' . $window . 's');
            Cache::forget($key);

            SecurityViolation::create([
                'user_id' => $user->sId,
                'ip' => $request->ip(),
                'type' => SecurityViolation::TYPE_BOT_BURST,
                'details' => [
                    'attempts' => count($timestamps),
                    'window_seconds' => $window,
                    'threshold' => $threshold,
                    'route' => $request->path(),
                ],
            ]);

            Log::warning('Account auto-banned for transaction burst', [
                'user_id' => $user->sId,
                'ip' => $request->ip(),
                'attempts' => count($timestamps),
                'window_seconds' => $window,
            ]);

            try {
                if (! empty($user->sEmail)) {
                    Mail::to($user->sEmail)->queue(new AccountBanned($user));
                }
                $adminEmail = config('mail.admin_address') ?? config('mail.from.address');
                if ($adminEmail) {
                    Mail::to($adminEmail)->queue(new AdminSecurityAlert(
                        'Account auto-banned (transaction burst)',
                        [
                            'user_id' => $user->sId,
                            'email' => $user->sEmail,
                            'ip' => $request->ip(),
                            'attempts' => count($timestamps),
                            'window_seconds' => $window,
                        ]
                    ));
                }
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch ban notification', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'message' => 'Your account has been suspended due to suspicious activity. Please contact support.',
            ], 423);
        }

        return $next($request);
    }
}
