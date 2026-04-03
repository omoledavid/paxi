<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Mail\WalletFunded;
use App\Models\ApiConfig;
use App\Models\User;
use App\Services\Palmpay\VirtualAccountService as PalmpayVAService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PalmpayVirtualAccountController extends Controller
{
    /**
     * PalmPay VA cash-in orderStatus codes:
     *   1 = SUCCESS (payment received into virtual account)
     *
     * Note: these differ from the bill-payment order status codes
     * (where 2 = success). The VA cash-in API uses 1 for success.
     */
    private const ORDER_STATUS_SUCCESS = 1;

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();

        Log::info('PalmPay VA Webhook received', ['payload' => $payload]);

        // Signature is required per PalmPay docs
        $sign = $payload['sign'] ?? '';

        if (empty($sign)) {
            Log::warning('PalmPay VA Webhook: missing signature');
            return response('missing signature', 400);
        }

        $palmpayService = new PalmpayVAService();

        if (! $palmpayService->verifyCallbackSignature($payload, $sign)) {
            Log::warning('PalmPay VA Webhook: invalid signature', ['payload' => $payload]);
            return response('invalid signature', 401);
        }

        $orderStatus      = (int) ($payload['orderStatus']      ?? -1);
        $merchantUserId   = $payload['merchantUserId']          ?? null;
        $virtualAccountNo = $payload['virtualAccountNo']        ?? null;
        $orderNo          = $payload['orderNo']                 ?? '';

        // orderAmount is in kobo — 10000 kobo = ₦100
        $orderAmountKobo = (int) ($payload['orderAmount'] ?? 0);
        $amountNaira     = $orderAmountKobo / 100;

        if ($orderStatus !== self::ORDER_STATUS_SUCCESS) {
            Log::info('PalmPay VA Webhook: non-success orderStatus, ignoring', [
                'orderStatus' => $orderStatus,
                'orderNo'     => $orderNo,
            ]);
            // Respond 200 + plain "success" to stop PalmPay retries
            return response('success', 200);
        }

        if ($amountNaira <= 0) {
            Log::warning('PalmPay VA Webhook: invalid orderAmount', ['orderAmount' => $orderAmountKobo]);
            return response('invalid amount', 400);
        }

        // Locate user — prefer merchantUserId (sId) if present, fall back to virtualAccountNo
        $user = null;

        if ($merchantUserId) {
            $user = User::find((int) $merchantUserId);
        }

        if (! $user && $virtualAccountNo) {
            $user = User::where('sBankNo', $virtualAccountNo)->first();
        }

        if (! $user) {
            Log::error('PalmPay VA Webhook: user not found', [
                'merchantUserId'   => $merchantUserId,
                'virtualAccountNo' => $virtualAccountNo,
                'orderNo'          => $orderNo,
            ]);
            // Respond success to stop retries — this user genuinely does not exist
            return response('success', 200);
        }

        // Apply wallet funding charges if configured in admin palmpay-setting
        $config  = ApiConfig::all();
        $charges = (float) (getConfigValue($config, 'palmpayVirtualAccountCharges') ?? 0);

        $amountToCredit = $amountNaira;
        if ($charges > 0) {
            if ($charges >= 1) {
                // Fixed naira charge (e.g. 50 = ₦50 flat fee)
                $amountToCredit = max(0, $amountNaira - $charges);
            } else {
                // Percentage (e.g. 0.015 = 1.5%)
                $amountToCredit = $amountNaira - ($amountNaira * $charges);
            }
        }

        $chargesText = $charges > 0
            ? ($charges >= 1
                ? " with a N{$charges} service charge"
                : ' with a ' . ($charges * 100) . '% service charge')
            : '';

        $payerName = $payload['payerAccountName'] ?? 'unknown sender';
        $payerBank = $payload['payerBankName']    ?? '';

        $serviceDesc = "Wallet funding of N{$amountNaira} received from {$payerName}"
            . ($payerBank ? " ({$payerBank})" : '')
            . " via PalmPay virtual account{$chargesText}."
            . " Your wallet has been credited with N{$amountToCredit}.";

        try {
            $result = creditWallet(
                $user,
                $amountToCredit,
                'Wallet Topup',
                $serviceDesc,
                0,              // status 0 = success
                0,              // profit
                $orderNo ?: null
            );

            Log::info('PalmPay VA Webhook: wallet credited', [
                'user_id'      => $user->sId,
                'amount_naira' => $amountToCredit,
                'orderNo'      => $orderNo,
            ]);

            // Send email notification — catch separately so a mail failure never blocks the 200 response
            try {
                Mail::to($user->sEmail)->send(new WalletFunded(
                    firstName:      $user->sFname,
                    amountReceived: $amountNaira,
                    amountCredited: $amountToCredit,
                    newBalance:     (float) ($result['new_balance'] ?? $user->fresh()->sWallet),
                    payerName:      $payerName,
                    payerBank:      $payerBank,
                    orderNo:        $orderNo,
                ));
            } catch (\Throwable $mailException) {
                Log::warning('PalmPay VA Webhook: failed to send funding email', [
                    'user_id' => $user->sId,
                    'error'   => $mailException->getMessage(),
                ]);
            }

            // PalmPay requires plain-text "success" — not JSON
            return response('success', 200);
        } catch (\Throwable $e) {
            Log::error('PalmPay VA Webhook: failed to credit wallet', [
                'user_id' => $user->sId,
                'error'   => $e->getMessage(),
            ]);

            // Non-200 tells PalmPay to retry
            return response('error', 500);
        }
    }
}
