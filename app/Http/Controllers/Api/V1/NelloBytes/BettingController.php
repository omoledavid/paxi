<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\FundBettingRequest;
use App\Http\Requests\NelloBytes\VerifyBettingCustomerRequest;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\BettingService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class BettingController extends Controller
{
    use ApiResponses;

    protected BettingService $bettingService;

    public function __construct(BettingService $bettingService)
    {
        $this->bettingService = $bettingService;
    }

    /**
     * Get betting companies
     *
     * @return JsonResponse
     */
    public function getCompanies(): JsonResponse
    {
        try {
            $companies = $this->bettingService->getCompanies();

            return $this->ok('Betting companies retrieved successfully', $companies);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve betting companies', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Failed to retrieve betting companies', 500, $e->getMessage());
        }
    }

    /**
     * Verify betting customer
     *
     * @param VerifyBettingCustomerRequest $request
     * @return JsonResponse
     */
    public function verifyCustomer(VerifyBettingCustomerRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $result = $this->bettingService->verifyCustomer(
                $validated['company_code'],
                $validated['customer_id']
            );

            return $this->ok('Customer verified successfully', $result);
        } catch (\Exception $e) {
            Log::error('Failed to verify betting customer', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $statusCode = method_exists($e, 'getCode') && $e->getCode() > 0 ? $e->getCode() : 500;

            return $this->error($e->getMessage(), $statusCode);
        }
    }

    /**
     * Fund betting account
     *
     * @param FundBettingRequest $request
     * @return JsonResponse
     */
    public function fund(FundBettingRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        // Verify PIN
        if ($user->sPin != $validated['pin']) {
            return $this->error('Incorrect PIN', 400);
        }

        // Generate transaction reference
        $transactionRef = generateTransactionRef();

        // Remove sensitive data from request payload before storing
        $requestPayload = $validated;
        unset($requestPayload['pin']);

        $callbackUrl = config('nellobytes.betting_callback_url') ?: URL::to('/webhooks/nellobytes');

        // Create transaction record before debit so we can log failures separately
        $transaction = NelloBytesTransaction::create([
            'user_id' => $user->sId,
            'service_type' => NelloBytesServiceType::BETTING,
            'transaction_ref' => $transactionRef,
            'amount' => $validated['amount'],
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        DB::beginTransaction();
        try {
            // Debit user wallet
            $debit = debitWallet(
                $user,
                (float) $validated['amount'],
                'Betting Funding',
                sprintf(
                    'Betting funding for %s (%s)',
                    $validated['customer_id'],
                    $validated['company_code']
                ),
                0,
                0,
                $transactionRef,
                false
            );

            $result = $this->bettingService->fund(
                $validated['company_code'],
                $validated['customer_id'],
                $validated['amount'],
                $transactionRef,
                $callbackUrl
            );

            // Update transaction with response
            $nellobytesRef = $result['reference'] ?? $result['ref'] ?? null;
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $result,
            ]);

            DB::commit();

            return $this->ok('Betting account funded successfully', [
                'transaction_ref' => $transactionRef,
                'nellobytes_ref' => $nellobytesRef,
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

            Log::error('Failed to fund betting account', [
                'user_id' => $user->sId,
                'transaction_ref' => $transactionRef,
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

