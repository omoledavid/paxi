<?php

namespace App\Http\Middleware;

use App\Models\SecurityViolation;
use App\Support\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = SecuritySettings::get();
        $required = $settings && (int) ($settings->require_kyc_for_transactions ?? 1) === 1;

        if (! $required) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->isVerifiedForTransactions()) {
            SecurityViolation::create([
                'user_id' => $user->sId,
                'ip' => $request->ip(),
                'type' => SecurityViolation::TYPE_UNVERIFIED,
                'details' => [
                    'kyc_status' => $user->kyc_status,
                    'email_verified' => (bool) $user->sRegStatus,
                    'mobile_verified' => (bool) $user->sMobileVerified,
                    'route' => $request->path(),
                ],
            ]);

            Log::warning('Unverified user blocked from transaction', [
                'user_id' => $user->sId,
                'ip' => $request->ip(),
                'route' => $request->path(),
            ]);

            return response()->json([
                'message' => 'Please complete your verification to perform transactions.',
            ], 403);
        }

        return $next($request);
    }
}
