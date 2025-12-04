<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Traits\ApiResponses;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ThrottleEmailVerification
{
    use ApiResponses;

    public function handle(Request $request, Closure $next, string $type = 'email'): Response
    {
        $email = $request->input('email');

        if (!$email) {
            return $next($request);
        }

        $user = User::where('sEmail', $email)->first();

        if (!$user) {
            return $next($request);
        }

        $attemptField = $type === 'password' ? 'sPasswordResetAttempts' : 'sEmailVerificationAttempts';
        $resetAtField = $type === 'password' ? 'sPasswordResetAttemptsResetAt' : 'sEmailVerificationAttemptsResetAt';
        $flowName = $type === 'password' ? 'password reset' : 'email verification';

        $resetAt = $user->$resetAtField;
        $attempts = $user->$attemptField ?? 0;

        if ($resetAt && Carbon::now()->greaterThanOrEqualTo(Carbon::parse($resetAt)->addDay())) {
            $attempts = 0;
            $resetAt = Carbon::now();
        }

        if (!$resetAt) {
            $resetAt = Carbon::now();
        }

        if ($attempts >= 3) {
            $nextResetTime = Carbon::parse($resetAt)->addDay();
            $hoursRemaining = $nextResetTime->diffInHours(Carbon::now());
            $minutesRemaining = $nextResetTime->diffInMinutes(Carbon::now()) % 60;

            return $this->error(
                "Maximum {$flowName} attempts reached. Please try again in {$hoursRemaining} hours and {$minutesRemaining} minutes.",
                429
            );
        }

        $user->$attemptField = $attempts + 1;
        $user->$resetAtField = $resetAt;
        $user->save();

        return $next($request);
    }
}
