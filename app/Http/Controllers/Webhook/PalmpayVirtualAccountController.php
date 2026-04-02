<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\ApiConfig;
use App\Models\User;
use App\Services\Palmpay\VirtualAccountService as PalmpayVAService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PalmpayVirtualAccountController extends Controller
{
    // PalmPay orderStatus value for a successful cash-in
    private const ORDER_STATUS_SUCCESS = 2;

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();

        Log::info('PalmPay VA Webhook received', ['payload' => $payload]);

        // Signature is a required field per PalmPay docs
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

        // Per docs: orderStatus 2 = success
        $orderStatus      = (int) ($payload['orderStatus']      ?? -1);
        $merchantUserId   = $payload['merchantUserId']          ?? null;
        $virtualAccountNo = $payload['virtualAccountNo']        ?? null;
        $orderNo          = $payload['orderNo']                 ?? '';

        // orderAmount is in cents (100 = 1 NGN) per PalmPay docs
        $orderAmountKobo = (int) ($payload['orderAmount'] ?? 0);
        $amountNaira     = $orderAmountKobo / 100;

        if ($orderStatus !== self::ORDER_STATUS_SUCCESS) {
            Log::info('PalmPay VA Webhook: non-success orderStatus, ignoring', [
                'orderStatus' => $orderStatus,
                'orderNo'     => $orderNo,
            ]);
            // Must respond 200 + plain "success" to stop PalmPay retries
            return response('success', 200);
        }

        if ($amountNaira <= 0) {
            Log::warning('PalmPay VA Webhook: invalid orderAmount', ['orderAmount' => $orderAmountKobo]);
            return response('invalid amount', 400);
        }

        // Locate user — prefer merchantUserId (sId set at VA creation), fall back to account number
        $user = null;

        if (! $user && $virtualAccountNo) {
            $user = User::where('sBankNo', $virtualAccountNo)->first();
        }

        if (! $user) {
            Log::error('PalmPay VA Webhook: user not found', [
                'merchantUserId'   => $merchantUserId,
                'virtualAccountNo' => $virtualAccountNo,
                'orderNo'          => $orderNo,
            ]);
            // Return success to PalmPay to stop retries — the user genuinely doesn't exist
            return response('success', 200);
        }

        // Apply wallet funding charges if configured (from admin palmpay-setting)
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
                ? " with a ₦{$charges} service charge"
                : ' with a ' . ($charges * 100) . '% service charge')
            : '';

        $serviceDesc = "Wallet funding of ₦{$amountNaira} via PalmPay virtual account{$chargesText}."
            . " Your wallet has been credited with ₦{$amountToCredit}.";

        try {
            creditWallet(
                $user,
                $amountToCredit,
                'Wallet Topup',
                $serviceDesc,
                0,             // status 0 = success
                0,             // profit
                $orderNo ?: null
            );

            Log::info('PalmPay VA Webhook: wallet credited', [
                'user_id'        => $user->sId,
                'amount_naira'   => $amountToCredit,
                'orderNo'        => $orderNo,
            ]);

            // PalmPay requires plain-text "success" response — not JSON
            return response('success', 200);
        } catch (\Throwable $e) {
            Log::error('PalmPay VA Webhook: failed to credit wallet', [
                'user_id' => $user->sId,
                'error'   => $e->getMessage(),
            ]);

            // Return non-200 so PalmPay will retry
            return response('error', 500);
        }
    }
}
