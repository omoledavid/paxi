<?php

namespace App\Http\Controllers;

use App\Enums\PaystackServiceType;
use App\Enums\TransactionStatus;
use App\Exceptions\PaystackApiException;
use App\Models\ApiConfig;
use App\Models\PaystackTransaction;
use App\Models\User;
use App\Services\Paystack\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackCheckoutController extends Controller
{
    public function initialize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'       => ['required', 'numeric', 'min:100'],
            'redirect_url' => ['nullable', 'string'],
        ]);

        $user   = auth()->user();
        $config = ApiConfig::all();

        if (getConfigValue($config, 'paystackCheckoutStatus') !== 'On') {
            return response()->json([
                'status'  => 503,
                'message' => 'Instant funding is currently unavailable.',
            ], 503);
        }

        $transRef   = (string) generateTransactionRef();
        $amountKobo = (int) ($validated['amount'] * 100);

        // Backend handles the Paystack callback; store the frontend destination for later redirect
        $backendCallbackUrl  = rtrim(env('APP_URL', ''), '/').'/paystack/callback';
        $frontendRedirectUrl = $validated['redirect_url'] ?? rtrim(env('FRONTEND_URL', ''), '/').'/app/payment/callback';

        $payload = [
            'email'        => $user->sEmail,
            'amount'       => $amountKobo,
            'reference'    => $transRef,
            'callback_url' => $backendCallbackUrl.'?reference='.$transRef,
            'metadata'     => [
                'user_id' => $user->sId,
                'service' => 'wallet_topup',
            ],
        ];

        $transaction = PaystackTransaction::create([
            'user_id'         => $user->sId,
            'service_type'    => PaystackServiceType::WALLET_TOPUP,
            'transaction_ref' => $transRef,
            'amount'          => $validated['amount'],
            'status'          => TransactionStatus::PENDING,
            'request_payload' => $payload,
            'redirect_url'    => $frontendRedirectUrl,
        ]);

        try {
            $response = (new CheckoutService())->initializeTransaction($payload);

            $transaction->update([
                'paystack_ref' => $response['data']['reference'] ?? null,
            ]);

            return response()->json([
                'status'  => 200,
                'message' => 'Payment link generated successfully.',
                'data'    => [
                    'authorization_url' => $response['data']['authorization_url'],
                    'reference'         => $transRef,
                ],
            ]);
        } catch (PaystackApiException $e) {
            $transaction->update(['status' => TransactionStatus::FAILED]);

            return response()->json([
                'status'  => 502,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Paystack redirects the user's browser here after payment.
     * Verify the payment, credit the wallet, then redirect to the frontend.
     */
    public function callback(Request $request): RedirectResponse
    {
        $reference = $request->query('reference') ?? $request->query('trxref');

        if (! $reference) {
            Log::warning('Paystack Callback: missing reference in query string');
            return $this->redirectToFrontend(null, 'failed', 'Invalid payment reference.');
        }

        $transaction = PaystackTransaction::where('transaction_ref', $reference)
            ->where('service_type', PaystackServiceType::WALLET_TOPUP->value)
            ->first();

        if (! $transaction) {
            Log::error('Paystack Callback: transaction not found', compact('reference'));
            return $this->redirectToFrontend(null, 'failed', 'Transaction not found.');
        }

        $frontendUrl = $transaction->redirect_url
            ?? rtrim(env('FRONTEND_URL', ''), '/').'/app/payment/callback';

        // Already credited (webhook may have arrived first) — just redirect with success
        if ($transaction->status === TransactionStatus::SUCCESS) {
            return redirect($frontendUrl.'?payment=success&amount='.$transaction->amount);
        }

        if ($transaction->status === TransactionStatus::FAILED) {
            return redirect($frontendUrl.'?payment=failed');
        }

        // Verify with Paystack API
        try {
            $verification = (new CheckoutService())->verifyTransaction($reference);
        } catch (PaystackApiException $e) {
            Log::error('Paystack Callback: verification failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return redirect($frontendUrl.'?payment=failed');
        }

        $verifiedStatus = $verification['data']['status'] ?? '';
        $amountKobo     = (int) ($verification['data']['amount'] ?? 0);
        $amountNaira    = $amountKobo / 100;

        if ($verifiedStatus !== 'success') {
            $transaction->update(['status' => TransactionStatus::FAILED]);
            return redirect($frontendUrl.'?payment=failed');
        }

        $user = User::where('sId', $transaction->user_id)->first();

        if (! $user) {
            Log::error('Paystack Callback: user not found', ['user_id' => $transaction->user_id]);
            return redirect($frontendUrl.'?payment=failed');
        }

        $config         = ApiConfig::all();
        $charges        = (float) (getConfigValue($config, 'paystackCheckoutCharges') ?? 0);
        $amountToCredit = $amountNaira;

        if ($charges > 0) {
            $amountToCredit = $amountNaira - ($amountNaira * ($charges / 100));
        }

        $chargesText = $charges > 0 ? " with a {$charges}% service charge" : '';
        $serviceDesc = "Wallet funding of ₦{$amountNaira} via Paystack Instant Funding{$chargesText}."
            ." Your wallet has been credited with ₦{$amountToCredit}.";

        try {
            DB::transaction(function () use ($transaction, $user, $amountToCredit, $serviceDesc, $reference, $verification) {
                // Re-check inside transaction to prevent race with webhook
                $fresh = PaystackTransaction::where('id', $transaction->id)
                    ->lockForUpdate()
                    ->first();

                if ($fresh->status === TransactionStatus::SUCCESS) {
                    return; // Webhook already credited — skip
                }

                $fresh->update([
                    'status'           => TransactionStatus::SUCCESS,
                    'paystack_ref'     => $verification['data']['reference'] ?? null,
                    'response_payload' => $verification['data'],
                ]);

                creditWallet(
                    $user,
                    $amountToCredit,
                    'Wallet Topup',
                    $serviceDesc,
                    1,
                    0,
                    $reference,
                    false
                );
            });

            Log::info('Paystack Callback: wallet credited', [
                'user_id'   => $user->sId,
                'amount'    => $amountToCredit,
                'reference' => $reference,
            ]);

            return redirect($frontendUrl.'?payment=success&amount='.$amountToCredit);
        } catch (\Throwable $e) {
            Log::error('Paystack Callback: failed to credit wallet', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return redirect($frontendUrl.'?payment=failed');
        }
    }

    private function redirectToFrontend(?PaystackTransaction $transaction, string $status, string $message = ''): RedirectResponse
    {
        $base = $transaction?->redirect_url
            ?? rtrim(env('FRONTEND_URL', ''), '/').'/app/payment/callback';

        return redirect($base.'?payment='.$status);
    }

    public function verify(Request $request, string $reference): JsonResponse
    {
        $user = auth()->user();

        $transaction = PaystackTransaction::where('transaction_ref', $reference)
            ->where('user_id', $user->sId)
            ->where('service_type', PaystackServiceType::WALLET_TOPUP->value)
            ->first();

        if (! $transaction) {
            return response()->json([
                'status'  => 404,
                'message' => 'Transaction not found.',
            ], 404);
        }

        if ($transaction->status === TransactionStatus::SUCCESS) {
            return response()->json([
                'status'  => 200,
                'message' => 'Payment successful.',
                'data'    => ['status' => 'success', 'amount' => $transaction->amount],
            ]);
        }

        if ($transaction->status === TransactionStatus::FAILED) {
            return response()->json([
                'status'  => 200,
                'message' => 'Payment failed.',
                'data'    => ['status' => 'failed'],
            ]);
        }

        return response()->json([
            'status'  => 200,
            'message' => 'Payment is being processed.',
            'data'    => ['status' => 'pending_webhook'],
        ]);
    }
}
