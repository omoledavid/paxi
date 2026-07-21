<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\PalmpayTransaction;
use App\Services\Palmpay\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PalmpayPayoutWebhookController extends Controller
{
    /**
     * PalmPay payout orderStatus codes:
     *   0 = unpaid, 1 = paying, 2 = success, 3 = fail, 4 = close
     */
    private const ORDER_STATUS_SUCCESS = 2;
    private const ORDER_STATUS_FAILED  = 3;

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();

        Log::info('PalmPay Payout Webhook received', ['payload' => $payload]);

        // Signature verification
        $sign = $payload['sign'] ?? '';

        if (empty($sign)) {
            Log::warning('PalmPay Payout Webhook: missing signature');
            return response('missing signature', 400);
        }

        $service = new PayoutService();

        if (! $service->verifyCallbackSignature($payload, $sign)) {
            Log::warning('PalmPay Payout Webhook: invalid signature', ['payload' => $payload]);
            return response('invalid signature', 401);
        }

        $orderNo     = $payload['orderNo']     ?? '';
        $orderId     = $payload['orderId']      ?? '';
        $orderStatus = (int) ($payload['orderStatus'] ?? -1);

        // Find the transaction by transaction_ref (orderId) or provider_ref (orderNo)
        $transaction = PalmpayTransaction::where('transaction_ref', $orderId)
            ->orWhere('provider_ref', $orderNo)
            ->first();

        if (! $transaction) {
            Log::error('PalmPay Payout Webhook: transaction not found', [
                'orderNo' => $orderNo,
                'orderId' => $orderId,
            ]);
            return response('success', 200);
        }

        // Idempotency: already processed as success
        if ($transaction->status === TransactionStatus::SUCCESS) {
            Log::info('PalmPay Payout Webhook: already processed', ['orderId' => $orderId]);
            return response('success', 200);
        }

        // Already refunded/failed — don't process again
        if ($transaction->status === TransactionStatus::FAILED) {
            Log::info('PalmPay Payout Webhook: already failed/refunded', ['orderId' => $orderId]);
            return response('success', 200);
        }

        if ($orderStatus === self::ORDER_STATUS_SUCCESS) {
            $transaction->update([
                'status'           => TransactionStatus::SUCCESS,
                'response_payload' => $payload,
            ]);

            Log::info('PalmPay Payout Webhook: payout successful', [
                'orderId' => $orderId,
                'orderNo' => $orderNo,
            ]);

            return response('success', 200);
        }

        if ($orderStatus === self::ORDER_STATUS_FAILED || $orderStatus === 4) {
            // Refund the user
            $user = $transaction->user;

            if ($user) {
                $requestPayload = $transaction->request_payload ?? [];
                $totalDebit     = (float) ($requestPayload['total_debit'] ?? $transaction->amount);

                try {
                    creditWallet(
                        $user,
                        $totalDebit,
                        'Withdrawal Refund',
                        sprintf(
                            'Refund for failed bank withdrawal (Ref: %s). ₦%s returned to wallet.',
                            $transaction->transaction_ref,
                            number_format($totalDebit, 2)
                        ),
                        0,
                        0,
                        'REFUND-' . $transaction->transaction_ref
                    );

                    Log::info('PalmPay Payout Webhook: refund credited', [
                        'user_id'    => $user->sId,
                        'amount'     => $totalDebit,
                        'orderId'    => $orderId,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('PalmPay Payout Webhook: refund failed', [
                        'user_id' => $user->sId,
                        'error'   => $e->getMessage(),
                        'orderId' => $orderId,
                    ]);
                    // Return non-200 so PalmPay retries
                    return response('refund error', 500);
                }
            }

            $transaction->update([
                'status'           => TransactionStatus::FAILED,
                'response_payload' => $payload,
                'error_message'    => 'Payout failed (orderStatus: ' . $orderStatus . ')',
            ]);

            return response('success', 200);
        }

        // Other statuses (0=unpaid, 1=paying) — still processing, acknowledge
        Log::info('PalmPay Payout Webhook: intermediate status', [
            'orderStatus' => $orderStatus,
            'orderId'     => $orderId,
        ]);

        $transaction->update(['response_payload' => $payload]);

        return response('success', 200);
    }
}
