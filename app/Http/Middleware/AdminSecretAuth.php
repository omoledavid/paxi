<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdminSecretAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = config('services.admin_secret');

        if (empty($expectedSecret)) {
            Log::error('Admin endpoint called but ADMIN_SECRET is not configured');

            return response()->json([
                'status' => 'error',
                'message' => 'Admin secret not configured on the server.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $providedSecret = (string) $request->header('X-Admin-Secret');
        if (empty($providedSecret)) {
            $auth = $request->header('Authorization', '');
            if (str_starts_with($auth, 'Bearer ')) {
                $providedSecret = substr($auth, 7);
            }
        }

        if (! hash_equals($expectedSecret, $providedSecret)) {
            Log::warning('Unauthorized admin API attempt', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
