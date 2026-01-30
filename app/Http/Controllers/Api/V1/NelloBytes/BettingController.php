<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Enums\VtuAfricaServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\FundBettingRequest;
use App\Http\Requests\NelloBytes\VerifyBettingCustomerRequest;
use App\Models\ApiConfig;
use App\Models\NelloBytesTransaction;
use App\Models\PaystackTransaction;
use App\Models\VtuAfricaTransaction;
use App\Services\NelloBytes\BettingService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Services\Paystack\BettingService as PaystackBettingService;
use App\Services\Paystack\PaystackTransactionService;
use App\Services\VtuAfrica\BettingService as VtuAfricaBettingService;
use App\Services\VtuAfrica\VtuAfricaTransactionService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class BettingController extends Controller
{
    use ApiResponses;

    protected BettingService $bettingService;

    protected PaystackBettingService $paystackBettingService;

    protected VtuAfricaBettingService $vtuAfricaBettingService;

    protected NelloBytesTransactionService $nelloBytesTransactionService;

    protected PaystackTransactionService $paystackTransactionService;

    protected VtuAfricaTransactionService $vtuAfricaTransactionService;

    public function __construct(
        BettingService $bettingService,
        NelloBytesTransactionService $nelloBytesTransactionService,
        PaystackBettingService $paystackBettingService,
        PaystackTransactionService $paystackTransactionService,
        VtuAfricaBettingService $vtuAfricaBettingService,
        VtuAfricaTransactionService $vtuAfricaTransactionService
    ) {
        $this->bettingService = $bettingService;
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
        $this->paystackBettingService = $paystackBettingService;
        $this->paystackTransactionService = $paystackTransactionService;
        $this->vtuAfricaBettingService = $vtuAfricaBettingService;
        $this->vtuAfricaTransactionService = $vtuAfricaTransactionService;
    }

    /**
     * Get betting companies
     */
    public function getCompanies(): JsonResponse
    {
        try {
            // Priority: VTU Africa -> Paystack -> NelloBytes
            if ($this->isVtuAfricaEnabled()) {
                $companies = $this->vtuAfricaBettingService->getCompanies();

                return $this->ok('Betting companies retrieved successfully', $companies);
            }

            if ($this->isPaystackEnabled()) {
                $companies = $this->paystackBettingService->getProviders();

                return $this->ok('Betting companies retrieved successfully', $companies);
            }

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
     */
    public function verifyCustomer(VerifyBettingCustomerRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Priority: VTU Africa -> Paystack -> NelloBytes
            if ($this->isVtuAfricaEnabled()) {
                $result = $this->vtuAfricaBettingService->verifyCustomer(
                    $validated['company_code'],
                    $validated['customer_id']
                );

                return $this->ok('Customer verified successfully', $result);
            }

            if ($this->isPaystackEnabled()) {
                $result = $this->paystackBettingService->validateCustomer(
                    $validated['company_code'],
                    $validated['customer_id']
                );

                return $this->ok('Customer verified successfully', $result);
            }

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
     */
    public function fund(FundBettingRequest $request): JsonResponse
    {
        // Check if any betting service is enabled
        if (!$this->isVtuAfricaEnabled() && !$this->isPaystackEnabled() && !$this->isNellobytesEnabled()) {
            return $this->error('Betting Service currently disabled');
        }

        $user = auth()->user();
        $validated = $request->validated();

        // Verify PIN
        if ($user->sPin != $validated['pin']) {
            return $this->error('Incorrect PIN', 400);
        }

        // Priority: VTU Africa -> Paystack -> NelloBytes
        if ($this->isVtuAfricaEnabled()) {
            return $this->fundVtuAfrica($validated, $user);
        }

        if ($this->isPaystackEnabled()) {
            return $this->fundPaystack($validated, $user);
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
            $this->nelloBytesTransactionService->handleProviderResponse(
                $result,
                $transaction,
                $user,
                $validated['amount']
            );

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

    private function isNellobytesEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'nellobytesStatus') === 'On' &&
                getConfigValue($config, 'nellobytesBettingStatus') === 'On';
        }

        return $enabled;
    }

    private function fundPaystack($validated, $user)
    {
        $transactionRef = generateTransactionRef();

        // Create Paystack transaction
        $transaction = PaystackTransaction::create([
            'user_id' => $user->sId,
            'service_type' => \App\Enums\PaystackServiceType::BETTING,
            'transaction_ref' => $transactionRef,
            'amount' => $validated['amount'],
            'status' => TransactionStatus::PENDING,
            'request_payload' => $validated,
        ]);

        DB::beginTransaction();
        try {
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

            $result = $this->paystackBettingService->fundWallet(
                provider: $validated['company_code'],
                customerId: $validated['customer_id'],
                amount: (float) $validated['amount'],
                email: $user->email
            );

            $this->paystackTransactionService->handleProviderResponse(
                $result,
                $transaction,
                $user,
                (float) $validated['amount']
            );

            DB::commit();

            return $this->ok('Betting account funded successfully', [
                'transaction_ref' => $transactionRef,
                // 'nellobytes_ref' => ... // Paystack ref
                'paystack_ref' => $result['data']['reference'] ?? null,
                'balance' => $debit['new_balance'],
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'response_payload' => ['error' => $e->getMessage()],
            ]);
            Log::error('Failed to fund betting account (Paystack)', [
                'user_id' => $user->sId,
                'transaction_ref' => $transactionRef,
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage(), 500);
        }
    }

    private function isPaystackEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'paystackStatus') === 'On' &&
                getConfigValue($config, 'paystackBettingStatus') === 'On';
        }

        return $enabled;
    }

    private function isVtuAfricaEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtuAfricaStatus') === 'On' &&
                getConfigValue($config, 'vtuAfricaBettingStatus') === 'On';
        }

        return $enabled;
    }

    private function fundVtuAfrica(array $validated, $user): JsonResponse
    {
        $transactionRef = generateTransactionRef();

        // Remove sensitive data from request payload before storing
        $requestPayload = $validated;
        unset($requestPayload['pin']);

        // Create VTU Africa transaction
        $transaction = VtuAfricaTransaction::create([
            'user_id' => $user->sId,
            'service_type' => VtuAfricaServiceType::BETTING,
            'transaction_ref' => $transactionRef,
            'amount' => $validated['amount'],
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        DB::beginTransaction();
        try {
            $charge = 50;
            $totalAmount = (float) $validated['amount'] + $charge;

            $debit = debitWallet(
                $user,
                $totalAmount,
                'Betting Funding',
                sprintf(
                    'Betting funding for %s (%s)',
                    $validated['customer_id'],
                    $validated['company_code']
                ),
                0,
                $charge,
                $transactionRef,
                false
            );

            $result = $this->vtuAfricaBettingService->fund(
                $validated['company_code'],
                $validated['customer_id'],
                (float) $validated['amount'],
                $transactionRef,
                $user->sPhone ?? null
            );

            $this->vtuAfricaTransactionService->handleProviderResponse(
                $result['raw_response'] ?? $result,
                $transaction,
                $user,
                (float) $validated['amount']
            );

            DB::commit();

            return $this->ok('Betting account funded successfully', [
                'transaction_ref' => $transactionRef,
                'vtuafrica_ref' => $result['reference'] ?? null,
                'balance' => $debit['new_balance'],
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'error_message' => $e->getMessage(),
                'response_payload' => ['error' => $e->getMessage()],
            ]);
            Log::error('Failed to fund betting account (VTU Africa)', [
                'user_id' => $user->sId,
                'transaction_ref' => $transactionRef,
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage(), 500);
        }
    }
}
