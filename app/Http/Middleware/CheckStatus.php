<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = auth()->user();

            // Check if both email and mobile are verified
            $emailVerified = $user->sRegStatus == 0;
            $mobileVerified = $user->sMobileVerified ?? false;

            if ($emailVerified && $mobileVerified) {
                return $next($request);
            } else {
                $message = 'You need to verify your account first.';
                $verificationStatus = [
                    'email_verified' => $emailVerified,
                    'mobile_verified' => $mobileVerified,
                ];

                if (!$emailVerified && !$mobileVerified) {
                    $message = 'You need to verify both your email and mobile number.';
                } elseif (!$emailVerified) {
                    $message = 'You need to verify your email address.';
                } elseif (!$mobileVerified) {
                    $message = 'You need to verify your mobile number.';
                }

                if ($request->is('api/*')) {
                    return response()->json([
                        'message' => $message,
                        'data' => $verificationStatus,
                    ], 403);
                } else {
                    return response()->json([
                        'message' => $message,
                    ], 403);
                }
            }
        }
        abort(403);
    }
}
