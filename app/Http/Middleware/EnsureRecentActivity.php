<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecentActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $this->resolveAccessToken($request);

        if (! $accessToken) {
            return $next($request);
        }

        $timeoutMinutes = (int) config('auth.idle_timeout', 30);
        $threshold = now()->subMinutes($timeoutMinutes);
        $lastUsed = $accessToken->last_used_at ?? $accessToken->created_at ?? now();

        if ($lastUsed->lessThanOrEqualTo($threshold)) {
            $accessToken->delete();

            return $this->expiredResponse();
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }

    protected function resolveAccessToken(Request $request): ?PersonalAccessToken
    {
        $plainTextToken = $request->bearerToken();

        if (blank($plainTextToken)) {
            return null;
        }

        return PersonalAccessToken::findToken($plainTextToken);
    }

    protected function expiredResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Session expired due to inactivity. Please sign in again.',
        ], 401);
    }
}

