<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Mail\WalletFunded;
use App\Models\ApiConfig;
use App\Models\PalmpayTransaction;
use App\Services\Palmpay\BankTransferService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PalmpayBankTransferWebhookController extends Controller
{
    /**
     * PalmPay bank transfer orderStatus codes:
     *   0 = unpaid, 1 = paying, 2 = success, 3 = fail, 4 = close
     *
     * Note: this differs from the VA cash-in webhook where 1 = success.
     */
    private const ORDER_STATUS_SUCCESS = 2;

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();

        Log::info('PalmPay BankTransfer Webhook received', ['payload' => $payload]);

        // Signature verification
        $sign = $payload['sign'] ?? '';

        if (empty($sign)) {
            Log::warning('PalmPay BankTransfer Webhook: missing signature');
            return response('missing signature', 400);
        }

        $service = new BankTransferService();

        if (! $service->verifyCallbackSignature($payload, $sign)) {
            Log::warning('PalmPay BankTransfer Webhook: invalid signature', ['payload' => $payload]);
            return response('invalid signature', 401);
        }

        $orderNo     = $payload['orderNo']     ?? '';
        $orderId     = $payload['orderId']      ?? '';
        $orderStatus = (int) ($payload['orderStatus'] ?? -1);

        // Bank transfer webhook uses "amount" (not "orderAmount" like VA cash-in).
        // Both are in kobo — 10000 kobo = ₦100
        $amountKobo  = (int) ($payload['amount'] ?? $payload['orderAmount'] ?? 0);
        $amountNaira = $amountKobo / 100;

        // Find the pending transaction by provider_ref (orderNo) or transaction_ref (orderId)
        $transaction = PalmpayTransaction::where('provider_ref', $orderNo)
            ->orWhere('transaction_ref', $orderId)
            ->first();

        if (! $transaction) {
            Log::error('PalmPay BankTransfer Webhook: transaction not found', [
                'orderNo'  => $orderNo,
                'orderId'  => $orderId,
            ]);
            // Respond success to stop retries
            return response('success', 200);
        }

        // Idempotency: already processed
        if ($transaction->status === TransactionStatus::SUCCESS) {
            Log::info('PalmPay BankTransfer Webhook: already processed', ['orderNo' => $orderNo]);
            return response('success', 200);
        }

        // Non-success status
        if ($orderStatus !== self::ORDER_STATUS_SUCCESS) {
            Log::info('PalmPay BankTransfer Webhook: non-success orderStatus', [
                'orderStatus' => $orderStatus,
                'orderNo'     => $orderNo,
            ]);

            $transaction->update([
                'status'           => TransactionStatus::FAILED,
                'response_payload' => $payload,
            ]);

            return response('success', 200);
        }

        if ($amountNaira <= 0) {
            Log::warning('PalmPay BankTransfer Webhook: invalid amount', ['amount_kobo' => $amountKobo]);
            return response('invalid amount', 400);
        }

        $user = $transaction->user;

        if (! $user) {
            Log::error('PalmPay BankTransfer Webhook: user not found', [
                'transaction_id' => $transaction->id,
            ]);
            return response('success', 200);
        }

        // Apply deposit fees using cap-based logic (configured in admin Deposit Settings).
        // Falls back to the flat palmpayBankTransferCharges when no cap is set.
        $config         = ApiConfig::all();
        $cap            = (float) (getConfigValue($config, 'checkoutDepositCap') ?? 0);
        $amountToCredit = $amountNaira;
        $feeApplied     = 0;

        if ($cap > 0) {
            if ($amountNaira <= $cap) {
                $feeType  = getConfigValue($config, 'checkoutBelowCapFeeType') ?? 'fixed';
                $feeValue = (float) (getConfigValue($config, 'checkoutBelowCapFee') ?? 0);
            } else {
                $feeType  = getConfigValue($config, 'checkoutAboveCapFeeType') ?? 'fixed';
                $feeValue = (float) (getConfigValue($config, 'checkoutAboveCapFee') ?? 0);
            }
            if ($feeValue > 0) {
                $feeApplied     = ($feeType === 'percent') ? $amountNaira * $feeValue / 100 : $feeValue;
                $amountToCredit = max(0, $amountNaira - $feeApplied);
            }
        } else {
            // Fallback: legacy flat charge stored as decimal (e.g. 0.015 = 1.5%) or Naira (>= 1)
            $charges = (float) (getConfigValue($config, 'palmpayBankTransferCharges') ?? 0);
            if ($charges > 0) {
                $feeApplied     = ($charges >= 1) ? $charges : $amountNaira * $charges;
                $amountToCredit = max(0, $amountNaira - $feeApplied);
            }
        }

        $payerName = $payload['payerAccountName'] ?? 'unknown sender';
        $payerBank = $payload['payerBankName']     ?? '';

        $chargesText = $feeApplied > 0
            ? ' with a N' . number_format($feeApplied, 2) . ' service charge'
            : '';

        $serviceDesc = "Bank transfer of N{$amountNaira} received from {$payerName}"
            . ($payerBank ? " ({$payerBank})" : '')
            . " via PalmPay{$chargesText}."
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

            $transaction->update([
                'status'           => TransactionStatus::SUCCESS,
                'response_payload' => $payload,
            ]);

            Log::info('PalmPay BankTransfer Webhook: wallet credited', [
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
                Log::warning('PalmPay BankTransfer Webhook: failed to send funding email', [
                    'user_id' => $user->sId,
                    'error'   => $mailException->getMessage(),
                ]);
            }

            // PalmPay requires plain-text "success" — not JSON
            return response('success', 200);
        } catch (\Throwable $e) {
            Log::error('PalmPay BankTransfer Webhook: failed to credit wallet', [
                'user_id' => $user->sId,
                'error'   => $e->getMessage(),
            ]);

            // Non-200 tells PalmPay to retry
            return response('error', 500);
        }
    }
}
