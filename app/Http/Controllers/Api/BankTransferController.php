<?php

namespace App\Http\Controllers\Api;

use App\Enums\PalmpayServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\PalmpayTransaction;
use App\Services\Palmpay\BankTransferService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BankTransferController extends Controller
{
    use ApiResponses;

    /**
     * Initiate a PalmPay bank transfer order.
     *
     * Creates a temporary virtual account that the user transfers into.
     * The webhook will credit the wallet once PalmPay confirms the payment.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:100'], // Minimum ₦100
        ]);

        $user        = $request->user();
        $amountNaira = (float) $request->input('amount');
        $orderId     = 'BT-' . $user->sId . '-' . Str::random(12);
        $notifyUrl   = rtrim(config('app.url'), '/') . '/webhooks/palmpay/bank-transfer';

        try {
            $service = new BankTransferService();
            $result  = $service->createOrder($user, $amountNaira, $orderId, $notifyUrl);

            // Store in palmpay_transactions for webhook lookup
            PalmpayTransaction::create([
                'user_id'          => $user->sId,
                'service_type'     => PalmpayServiceType::BANK_TRANSFER,
                'transaction_ref'  => $orderId,
                'provider_ref'     => $result['orderNo'],
                'amount'           => $amountNaira,
                'status'           => TransactionStatus::PENDING,
                'request_payload'  => ['amount' => $amountNaira],
                'response_payload' => $result,
            ]);

            return $this->ok('Bank transfer order created.', [
                'order_id'       => $orderId,
                'bank_name'      => $result['payerBankName'],
                'account_name'   => $result['payerAccountName'],
                'account_number' => $result['payerVirtualAccNo'],
                'amount'         => $amountNaira,
                'expire_seconds' => $result['expireTime'],
            ]);
        } catch (\Throwable $e) {
            Log::error('BankTransfer: failed to create order', [
                'user_id' => $user->sId,
                'error'   => $e->getMessage(),
            ]);

            return $this->error('Failed to generate payment account. Please try again.', 500);
        }
    }

    /**
     * Check the status of a pending bank transfer order.
     * Used by the frontend to poll for payment confirmation.
     */
    public function status(Request $request, string $orderId): JsonResponse
    {
        $transaction = PalmpayTransaction::where('transaction_ref', $orderId)
            ->where('user_id', $request->user()->sId)
            ->first();

        if (! $transaction) {
            return $this->error('Transaction not found.', 404);
        }

        return $this->ok('Transaction status retrieved.', [
            'status' => $transaction->status->value, // 'pending' | 'success' | 'failed'
        ]);
    }
}
