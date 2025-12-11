<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\PrintEpinRequest;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\EpinService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EpinController extends Controller
{
    use ApiResponses;

    protected EpinService $epinService;

    public function __construct(EpinService $epinService)
    {
        $this->epinService = $epinService;
    }

    /**
     * Get EPIN discounts
     *
     * @return JsonResponse
     */
    public function getDiscounts(): JsonResponse
    {
        try {
            $discounts = $this->epinService->getDiscounts();

            return $this->ok('EPIN discounts retrieved successfully', $discounts);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve EPIN discounts', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Failed to retrieve EPIN discounts', 500, $e->getMessage());
        }
    }

    /**
     * Print EPIN recharge card
     *
     * @param PrintEpinRequest $request
     * @return JsonResponse
     */
    public function printCard(PrintEpinRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        // Verify PIN
        if ($user->sPin != $validated['pin']) {
            return $this->error('Incorrect PIN', 400);
        }

        // Generate transaction reference (used as RequestID)
        $transactionRef = generateTransactionRef();

        $amount = (float) ($validated['value'] * $validated['quantity']);

        // Remove sensitive data from request payload before storing
        $requestPayload = $validated;
        unset($requestPayload['pin']);

        // Create transaction record
        $transaction = NelloBytesTransaction::create([
            'user_id' => $user->sId,
            'service_type' => NelloBytesServiceType::EPIN,
            'transaction_ref' => $transactionRef,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        DB::beginTransaction();
        try {
            // Debit wallet before making the purchase call
            $debit = debitWallet(
                $user,
                $amount,
                'EPIN Purchase',
                sprintf(
                    'EPIN %s x %s for %s',
                    number_format($validated['value'], 2),
                    $validated['quantity'],
                    $validated['mobile_network']
                ),
                0,
                0,
                $transactionRef,
                false
            );

            $result = $this->epinService->buyAirtimeEpin(
                $validated['mobile_network'],
                (int) $validated['value'],
                (int) $validated['quantity'],
                $transactionRef,
                $validated['callback_url'] ?? null
            );

            // Update transaction with response
            $txnPins = $result['TXN_EPIN'] ?? [];
            $primaryTxn = is_array($txnPins) && count($txnPins) ? $txnPins[0] : [];
            $nellobytesRef = $primaryTxn['transactionid'] ?? $result['orderid'] ?? $result['reference'] ?? $result['ref'] ?? null;
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $result,
            ]);

            DB::commit();

            return $this->ok('EPIN card printed successfully', [
                'transaction_ref' => $transactionRef,
                'nellobytes_ref' => $nellobytesRef,
                'amount' => $amount,
                'balance' => $debit['new_balance'],
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            // Update transaction with error
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'error_message' => $e->getMessage(),
                'error_code' => method_exists($e, 'getErrorCode') ? $e->getErrorCode() : null,
                'response_payload' => ['error' => $e->getMessage()],
            ]);

            Log::error('Failed to print EPIN card', [
                'user_id' => $user->sId,
                'transaction_ref' => $transactionRef,
                'request' => $validated,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $statusCode = method_exists($e, 'getCode') && $e->getCode() > 0 ? $e->getCode() : 500;

            return $this->error($e->getMessage(), $statusCode);
        }
    }

    /**
     * Query EPIN transaction by RequestID or OrderID
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function query(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => 'nullable|string',
            'order_id' => 'nullable|string',
        ]);

        if (empty($validated['request_id']) && empty($validated['order_id'])) {
            return $this->error('Either request_id or order_id is required', 422);
        }

        try {
            $result = $this->epinService->queryTransaction(
                $validated['request_id'] ?? null,
                $validated['order_id'] ?? null
            );

            // Optionally update stored transaction if we find it by request_id
            if (!empty($validated['request_id'])) {
                NelloBytesTransaction::where('transaction_ref', $validated['request_id'])
                    ->where('service_type', NelloBytesServiceType::EPIN)
                    ->latest()
                    ->first()?->update([
                            'response_payload' => $result,
                        ]);
            }

            return $this->ok('EPIN transaction retrieved successfully', $result);
        } catch (\Exception $e) {
            Log::error('Failed to query EPIN transaction', [
                'request' => $validated,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $statusCode = $e instanceof \RuntimeException
                ? 400
                : (method_exists($e, 'getCode') && $e->getCode() > 0 ? $e->getCode() : 500);

            return $this->error($e->getMessage(), $statusCode);
        }
    }
}

