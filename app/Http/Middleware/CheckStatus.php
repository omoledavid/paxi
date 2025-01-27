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
            if ($user->sRegStatus == 0) {
                return $next($request);
            } else {
                if ($request->is('api/*')) {
                    return response()->json([
                        'message' => 'You need to verify your account first.',
                        'data' => [
                            'email_verified' => false,
                        ],
                    ]);
                } else {
                    return response()->json([
                        'message' => 'You need to verify your account first.',
                    ]);
                }
            }
        }
        abort(403);
    }
}
