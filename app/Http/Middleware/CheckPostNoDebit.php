<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckPostNoDebit
{
    /**
     * Block any debit transaction when a Post No Debit (PND) flag is active on the account.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (int) $user->pnd_active === 1) {
            Log::info('PND block: debit attempt blocked', [
                'user_id' => $user->sId,
                'route'   => $request->path(),
                'ip'      => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Transactions are restricted on this account. Contact support.',
                'code'    => 'PND_ACTIVE',
            ], 403);
        }

        return $next($request);
    }
}
