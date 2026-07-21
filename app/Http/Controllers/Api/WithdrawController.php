<?php

namespace App\Http\Controllers\Api;

use App\Enums\PalmpayServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\PalmpayTransaction;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Services\Palmpay\PayoutService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WithdrawController extends Controller
{
    use ApiResponses;

    /**
     * Withdraw funds from wallet to a saved bank account via PalmPay payout.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $request->validate([
            'bank_account_id' => ['required', 'integer'],
            'amount'          => ['required', 'numeric', 'min:100'],
            'pin'             => ['required', 'digits:4'],
        ]);

        // --- Gate 1: Admin toggle ---
        Cache::forget('GeneralSetting');
        if (! gs('enable_bank_transfer')) {
            return $this->error('Bank withdrawals are currently disabled.', 403);
        }

        $user = $request->user();

        // --- Gate 2: Per-user permission ---
        if (! $user->can_transfer_to_bank) {
            return $this->error('You do not have permission to withdraw to bank.', 403);
        }

        // --- Gate 3: PIN verification ---
        if ($user->sPin != $request->input('pin')) {
            return $this->error('Incorrect PIN.', 400);
        }

        // --- Gate 4: Bank account ownership ---
        $bankAccount = UserBankAccount::forUser($user->sId)->find($request->input('bank_account_id'));

        if (! $bankAccount) {
            return $this->error('Bank account not found.', 404);
        }

        // --- Fee calculation (server-side only) ---
        $amount    = (float) $request->input('amount');
        $fee       = (float) (gs('bank_transfer_fee') ?? 0);
        $totalDebit = $amount + $fee;

        // --- Pre-check balance (fast fail before locking) ---
        if ((float) $user->sWallet < $totalDebit) {
            return $this->error('Insufficient wallet balance.', 400);
        }

        $orderId   = 'WD-' . $user->sId . '-' . Str::random(12);
        $notifyUrl = rtrim(config('app.url'), '/') . '/webhooks/palmpay/payout';

        // Create transaction record (PENDING) before debit attempt
        $transaction = PalmpayTransaction::create([
            'user_id'          => $user->sId,
            'service_type'     => PalmpayServiceType::PAYOUT,
            'transaction_ref'  => $orderId,
            'amount'           => $amount,
            'status'           => TransactionStatus::PENDING,
            'request_payload'  => [
                'bank_account_id' => $bankAccount->id,
                'bank_code'       => $bankAccount->bank_code,
                'bank_name'       => $bankAccount->bank_name,
                'account_number'  => $bankAccount->account_number,
                'account_name'    => $bankAccount->account_name,
                'amount'          => $amount,
                'fee'             => $fee,
                'total_debit'     => $totalDebit,
            ],
        ]);

        DB::beginTransaction();

        try {
            // Lock user row to prevent double-spend race condition
            $lockedUser = User::where('sId', $user->sId)->lockForUpdate()->first();

            if ((float) $lockedUser->sWallet < $totalDebit) {
                DB::rollBack();
                $transaction->update(['status' => TransactionStatus::FAILED, 'error_message' => 'Insufficient balance']);

                return $this->error('Insufficient wallet balance.', 400);
            }

            // Debit wallet (amount + fee)
            $debit = debitWallet(
                $lockedUser,
                $totalDebit,
                'Bank Withdrawal',
                sprintf(
                    'Withdrawal of N%s to %s - %s (%s). Fee: N%s.',
                    number_format($amount, 2),
                    $bankAccount->bank_name,
                    $bankAccount->account_name,
                    substr($bankAccount->account_number, 0, 3) . '****' . substr($bankAccount->account_number, -3),
                    number_format($fee, 2)
                ),
                0,
                0,
                $orderId,
                false,  // wrapInTransaction = false (we manage the transaction)
                false   // lockUser = false (we already locked)
            );

            // Call PalmPay payout API
            $service = new PayoutService();
            $result  = $service->payout(
                $orderId,
                $bankAccount->account_name,
                $bankAccount->bank_code,
                $bankAccount->account_number,
                (int) ($amount * 100), // Convert naira to kobo
                $notifyUrl,
                'Wallet withdrawal'
            );

            // Update transaction with provider response
            $transaction->update([
                'provider_ref'     => $result['orderNo'],
                'response_payload' => $result,
                'status'           => $result['orderStatus'] === 2
                    ? TransactionStatus::SUCCESS
                    : TransactionStatus::PENDING,
            ]);

            DB::commit();

            return $this->ok('Withdrawal initiated successfully.', [
                'transaction_ref' => $orderId,
                'amount'          => $amount,
                'fee'             => $fee,
                'total_debit'     => $totalDebit,
                'balance'         => $debit['new_balance'],
                'status'          => $result['orderStatus'] === 2 ? 'success' : 'processing',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            $transaction->update([
                'status'        => TransactionStatus::FAILED,
                'error_message' => $e->getMessage(),
                'error_code'    => method_exists($e, 'getErrorCode') ? $e->getErrorCode() : null,
            ]);

            Log::error('Withdraw: payout failed', [
                'user_id'         => $user->sId,
                'order_id'        => $orderId,
                'amount'          => $amount,
                'error'           => $e->getMessage(),
            ]);

            return $this->error('Withdrawal failed. Please try again.', 500);
        }
    }
}
