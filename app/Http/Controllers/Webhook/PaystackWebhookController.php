<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\PaystackServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiConfig;
use App\Models\PaystackTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function handleWebhook(Request $request): Response
    {
        $rawBody = $request->getContent();

        Log::info('Paystack Webhook received');

        // Verify HMAC-SHA512 signature before any DB work
        $signature = $request->header('X-Paystack-Signature');

        if (empty($signature)) {
            Log::warning('Paystack Webhook: missing X-Paystack-Signature header');
            return response('invalid', 401);
        }

        $config    = ApiConfig::all();
        $secretKey = getConfigValue($config, 'paystackApi');
        $computed  = hash_hmac('sha512', $rawBody, $secretKey);

        if (! hash_equals($computed, $signature)) {
            Log::warning('Paystack Webhook: signature mismatch');
            return response('invalid', 401);
        }

        $payload = json_decode($rawBody, true);
        $event   = $payload['event'] ?? '';

        Log::info('Paystack Webhook: event', ['event' => $event]);

        // Only process successful charge events
        if ($event !== 'charge.success') {
            return response('ok', 200);
        }

        $data        = $payload['data']        ?? [];
        $reference   = $data['reference']      ?? null;
        $innerStatus = $data['status']         ?? '';
        $amountKobo  = (int) ($data['amount']  ?? 0);
        $amountNaira = $amountKobo / 100;

        if ($innerStatus !== 'success') {
            Log::info('Paystack Webhook: non-success charge status, ignoring', compact('reference', 'innerStatus'));
            return response('ok', 200);
        }

        if ($amountNaira <= 0) {
            Log::warning('Paystack Webhook: invalid amount', compact('amountKobo', 'reference'));
            return response('ok', 200);
        }

        // Look up our pending transaction record
        $transaction = PaystackTransaction::where('transaction_ref', $reference)
            ->where('service_type', PaystackServiceType::WALLET_TOPUP->value)
            ->first();

        if (! $transaction) {
            Log::error('Paystack Webhook: no matching PaystackTransaction', compact('reference'));
            // Return 200 to stop Paystack retrying for refs we don't own
            return response('ok', 200);
        }

        // Idempotency gate — prevent double-credit
        if ($transaction->status === TransactionStatus::SUCCESS) {
            Log::info('Paystack Webhook: already processed, skipping', compact('reference'));
            return response('ok', 200);
        }

        $user = User::where('sId', $transaction->user_id)->first();

        if (! $user) {
            Log::error('Paystack Webhook: user not found', ['user_id' => $transaction->user_id]);
            return response('ok', 200);
        }

        // Apply charges (stored as percentage, e.g. 1.5 = 1.5%)
        $charges        = (float) (getConfigValue($config, 'paystackCheckoutCharges') ?? 0);
        $amountToCredit = $amountNaira;

        if ($charges > 0) {
            $amountToCredit = $amountNaira - ($amountNaira * ($charges / 100));
        }

        $chargesText = $charges > 0
            ? " with a {$charges}% service charge"
            : '';

        $serviceDesc = "Wallet funding of ₦{$amountNaira} via Paystack Instant Funding{$chargesText}."
            ." Your wallet has been credited with ₦{$amountToCredit}.";

        try {
            // Atomic: update transaction status AND credit wallet in one DB transaction
            DB::transaction(function () use ($transaction, $user, $amountToCredit, $serviceDesc, $reference, $data) {
                $transaction->update([
                    'status'           => TransactionStatus::SUCCESS,
                    'paystack_ref'     => $data['reference'] ?? null,
                    'response_payload' => $data,
                ]);

                creditWallet(
                    $user,
                    $amountToCredit,
                    'Wallet Topup',
                    $serviceDesc,
                    1,      // status 1 = credit
                    0,      // profit
                    $reference,
                    false   // wrapInTransaction = false, already inside DB::transaction
                );
            });

            Log::info('Paystack Webhook: wallet credited', [
                'user_id'   => $user->sId,
                'amount'    => $amountToCredit,
                'reference' => $reference,
            ]);

            return response('ok', 200);
        } catch (\Throwable $e) {
            Log::error('Paystack Webhook: failed to credit wallet', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            // Non-200 tells Paystack to retry
            return response('error', 500);
        }
    }
}
