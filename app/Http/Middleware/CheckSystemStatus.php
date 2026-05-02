<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckSystemStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $type): Response
    {
        // Cache settings for 5 minutes to avoid frequent DB queries
        $settings = Cache::remember('system_settings', 300, function () {
            return \App\Models\GeneralSetting::first();
        });

        if (! $settings) {
            return $next($request);
        }

        $message = null;
        $blocked = false;

        switch ($type) {
            case 'login':
                if ($settings->disable_login ?? 0) {
                    $message = 'Login is currently disabled. Please contact support.';
                    $blocked = true;
                }
                break;
            case 'signup':
                if ($settings->disable_signup ?? 0) {
                    $message = 'Registration is currently disabled. Please contact support.';
                    $blocked = true;
                }
                break;
            case 'transactions':
                if ($settings->disable_transactions ?? 0) {
                    $message = 'Transactions are currently disabled. Please contact support.';
                    $blocked = true;
                }
                break;
        }

        if ($blocked) {
            return response()->json([
                'message' => $message,
            ], 503);
        }

        return $next($request);
    }
}
