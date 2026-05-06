<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Models\SecurityViolation;
use App\Support\SecuritySettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnforceDailyTransactionLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $settings = SecuritySettings::get();
        if (! $settings) {
            return $next($request);
        }

        $cap = $this->resolveCap((int) $user->sType, $settings);
        if ($cap <= 0) {
            return $next($request);
        }

        $amount = (float) $request->input('amount', 0);
        $alreadySpent = $user->dailyTransactionTotal();
        $projected = $alreadySpent + $amount;

        if ($projected > $cap) {
            $remaining = max(0, $cap - $alreadySpent);

            SecurityViolation::create([
                'user_id' => $user->sId,
                'ip' => $request->ip(),
                'type' => SecurityViolation::TYPE_DAILY_LIMIT,
                'details' => [
                    'amount' => $amount,
                    'already_spent' => $alreadySpent,
                    'cap' => $cap,
                    'route' => $request->path(),
                ],
            ]);

            Log::warning('Daily transaction limit exceeded', [
                'user_id' => $user->sId,
                'amount' => $amount,
                'already_spent' => $alreadySpent,
                'cap' => $cap,
            ]);

            return response()->json([
                'message' => sprintf(
                    'This transaction would exceed your daily limit of ₦%s. Remaining today: ₦%s.',
                    number_format($cap, 2),
                    number_format($remaining, 2)
                ),
                'daily_limit' => $cap,
                'already_spent' => $alreadySpent,
                'remaining' => $remaining,
            ], 422);
        }

        return $next($request);
    }

    private function resolveCap(int $sType, $settings): float
    {
        return match ($sType) {
            AccountType::AGENT => (float) ($settings->daily_txn_limit_agent ?? 0),
            AccountType::VENDOR => (float) ($settings->daily_txn_limit_vendor ?? 0),
            default => (float) ($settings->daily_txn_limit_user ?? 0),
        };
    }
}
