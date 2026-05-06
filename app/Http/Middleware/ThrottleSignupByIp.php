<?php

namespace App\Http\Middleware;

use App\Mail\AdminSecurityAlert;
use App\Models\BlockedIp;
use App\Models\SecurityViolation;
use App\Models\SignupIpAttempt;
use App\Support\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class ThrottleSignupByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $settings = SecuritySettings::get();

        $max = (int) ($settings->signup_ip_max ?? 5);
        $windowHours = (int) ($settings->signup_ip_window_hours ?? 5);
        $blockHours = (int) ($settings->signup_ip_block_hours ?? 5);

        if ($max <= 0 || $windowHours <= 0) {
            return $next($request);
        }

        $blocked = BlockedIp::where('ip', $ip)->first();
        if ($blocked && $blocked->isActivelyBlocked()) {
            $message = $blocked->blocked_until === null
                ? 'Sign-up from this network is permanently blocked. Please contact support.'
                : 'Too many sign-up attempts from this network. Try again after ' . $blocked->blocked_until->diffForHumans() . '.';

            $response = ['message' => $message];
            if ($blocked->blocked_until !== null) {
                $response['retry_after'] = $blocked->blocked_until->toIso8601String();
            }

            return response()->json($response, 429);
        }

        $count = SignupIpAttempt::where('ip', $ip)
            ->where('created_at', '>=', now()->subHours($windowHours))
            ->count();

        if ($count >= $max) {
            // $blockHours == 0 means indefinite block (null stored in DB)
            $until = $blockHours > 0 ? now()->addHours($blockHours) : null;

            BlockedIp::updateOrCreate(
                ['ip' => $ip],
                ['blocked_until' => $until, 'reason' => 'Sign-up rate limit exceeded', 'created_at' => now()]
            );

            SecurityViolation::create([
                'user_id' => null,
                'ip' => $ip,
                'type' => SecurityViolation::TYPE_SIGNUP_IP,
                'details' => [
                    'attempts' => $count,
                    'max' => $max,
                    'window_hours' => $windowHours,
                    'block_hours' => $blockHours === 0 ? 'indefinite' : $blockHours,
                ],
            ]);

            Log::warning('IP blocked for sign-up rate limit', [
                'ip' => $ip,
                'attempts' => $count,
                'window_hours' => $windowHours,
                'blocked_until' => $until?->toDateTimeString() ?? 'indefinite',
            ]);

            try {
                $adminEmail = config('mail.admin_address') ?? config('mail.from.address');
                if ($adminEmail) {
                    Mail::to($adminEmail)->queue(new AdminSecurityAlert(
                        'IP auto-blocked (sign-up rate limit)',
                        [
                            'ip' => $ip,
                            'attempts' => $count,
                            'window_hours' => $windowHours,
                            'blocked_until' => $until?->toDateTimeString() ?? 'indefinite',
                        ]
                    ));
                }
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch IP block notification', ['error' => $e->getMessage()]);
            }

            $message = $until === null
                ? 'Sign-up from this network is permanently blocked. Please contact support.'
                : 'Too many sign-up attempts from this network. Try again in ' . $blockHours . ' hour(s).';

            $response = ['message' => $message];
            if ($until !== null) {
                $response['retry_after'] = $until->toIso8601String();
            }

            return response()->json($response, 429);
        }

        SignupIpAttempt::create(['ip' => $ip, 'created_at' => now()]);

        return $next($request);
    }
}
