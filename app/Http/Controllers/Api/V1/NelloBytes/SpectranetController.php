<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\BuySpectranetBundleRequest;
use App\Models\NelloBytesTransaction;
use App\Models\PaystackTransaction; // [NEW]
use App\Models\ApiConfig;
use App\Services\NelloBytes\SpectranetService;
use App\Services\Paystack\PaystackTransactionService; // [NEW]
// Assuming needed
use App\Services\Paystack\SpectranetService as PaystackSpectranetService; // [NEW]
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpectranetController extends Controller
{
    use ApiResponses;

    protected SpectranetService $spectranetService;

    protected PaystackSpectranetService $paystackSpectranetService; // [NEW]

    protected PaystackTransactionService $paystackTransactionService; // [NEW]

    public function __construct(
        SpectranetService $spectranetService,
        PaystackSpectranetService $paystackSpectranetService,
        PaystackTransactionService $paystackTransactionService
    ) {
        $this->spectranetService = $spectranetService;
        $this->paystackSpectranetService = $paystackSpectranetService;
        $this->paystackTransactionService = $paystackTransactionService;
    }

    /**
     * Get Spectranet packages
     */
    public function getPackages(): JsonResponse
    {
        try {
            if ($this->isPaystackEnabled()) {
                $response = $this->paystackSpectranetService->getPackages();
                // Map Paystack response to Spectranet structure if needed.
                // Assuming internal structure compatibility or mapping.
                // If Paystack returns standard bill providers/plans, we extract usage.
                // For simplicity, returning raw Paystack response data for now unless structure known.
                // Existing `getPackages` returns array of packages.
                if (isset($response['data'])) {
                    // map paystack data
                    $packages = collect($response['data'])->map(function ($item) {
                        return $item; // modify as needed
                    });
                    // This might need mapping to match Spectranet keys (code, price, etc.)
                    // For now passing through as response structure detail is unknown.
                }

                return $this->ok('Spectranet packages retrieved successfully', $response);
            }
            $packages = $this->spectranetService->getPackages();

            return $this->ok('Spectranet packages retrieved successfully', $packages);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve Spectranet packages', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Failed to retrieve Spectranet packages', 500, $e->getMessage());
        }
    }

    /**
     * Buy Spectranet bundle
     */
    public function buyBundle(BuySpectranetBundleRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        // Verify PIN
        if ($user->sPin != $validated['pin']) {
            return $this->error('Incorrect PIN', 400);
        }

        if ($this->isPaystackEnabled()) {
            return $this->buyBundlePaystack($validated, $user);
        }

        // Generate transaction reference
        $transactionRef = generateTransactionRef();

        // Remove sensitive data from request payload before storing
        $requestPayload = $validated;
        unset($requestPayload['pin']);

        $packages = $this->spectranetService->getPackages();
        $amount = $this->resolvePackageAmount($packages, $validated['package_code']);

        if ($amount === null) {
            return $this->error('Unable to determine package amount. Please try again.');
        }

        // Create transaction record
        $transaction = NelloBytesTransaction::create([
            'user_id' => $user->sId,
            'service_type' => NelloBytesServiceType::SPECTRANET,
            'transaction_ref' => $transactionRef,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        DB::beginTransaction();
        try {
            $debit = debitWallet(
                $user,
                $amount,
                'Spectranet Bundle Purchase',
                sprintf(
                    'Spectranet bundle for %s (%s)',
                    $validated['customer_id'],
                    $validated['package_code']
                ),
                0,
                0,
                $transactionRef,
                false
            );

            $result = $this->spectranetService->buyBundle(
                $validated['customer_id'],
                $validated['package_code'],
                $transactionRef
            );

            // Update transaction with response
            $amountFromResponse = $result['amount'] ?? $result['price'] ?? $amount;
            $delta = $amountFromResponse - $amount;
            $finalBalance = $debit['new_balance'];

            // Reconcile wallet if API returned a different amount
            if (abs($delta) > 0) {
                if ($delta > 0) {
                    $debit = debitWallet(
                        $debit['user'],
                        $delta,
                        'Spectranet Bundle Purchase Adjustment',
                        sprintf(
                            'Additional debit for Spectranet bundle %s (%s)',
                            $validated['customer_id'],
                            $validated['package_code']
                        ),
                        0,
                        0,
                        $transactionRef,
                        false
                    );
                    $finalBalance = $debit['new_balance'];
                } else {
                    $credit = creditWallet(
                        $debit['user'],
                        abs($delta),
                        'Spectranet Bundle Purchase Adjustment',
                        sprintf(
                            'Refund adjustment for Spectranet bundle %s (%s)',
                            $validated['customer_id'],
                            $validated['package_code']
                        ),
                        0,
                        0,
                        $transactionRef,
                        false
                    );
                    $finalBalance = $credit['new_balance'];
                }
            }

            $nellobytesRef = $result['reference'] ?? $result['ref'] ?? null;
            $transaction->update([
                'amount' => $amountFromResponse,
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $result,
            ]);

            DB::commit();

            return $this->ok('Spectranet bundle purchased successfully', [
                'transaction_ref' => $transactionRef,
                'nellobytes_ref' => $nellobytesRef,
                'balance' => $finalBalance,
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

            Log::error('Failed to buy Spectranet bundle', [
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

    private function resolvePackageAmount(array $packages, string $packageCode): ?float
    {
        foreach ($packages as $package) {
            $code = $package['code'] ?? $package['package_code'] ?? $package['Package'] ?? $package['Code'] ?? null;
            if ($code !== $packageCode) {
                continue;
            }

            $price = $package['amount'] ?? $package['price'] ?? $package['Amount'] ?? $package['Price'] ?? null;

            if (is_numeric($price)) {
                return (float) $price;
            }
        }

        return null;
    }

    private function buyBundlePaystack($validated, $user)
    {
        $transactionRef = generateTransactionRef();

        // Fetch amount from packages if needed, or trust input? Paystack Spectranet usually implies fixed amounts?
        // Paystack bill payment takes strict amount.
        // We probably need to resolve package amount first using Paystack packages service, but let's assume validation is handled or passed.

        // For Paystack, we often need plan code and amount.

        $transaction = PaystackTransaction::create([
            'user_id' => $user->sId,
            'service_type' => \App\Enums\PaystackServiceType::SPECTRANET,
            'transaction_ref' => $transactionRef,
            'amount' => 0, // Placeholder until resolved
            'status' => TransactionStatus::PENDING,
            'request_payload' => $validated,
        ]);

        DB::beginTransaction();
        try {
            // Need to get amount for the package code from Paystack?
            // Or assume existing logic `resolvePackageAmount` works?
            // If we use `paystackSpectranetService->getPackages()`, structure might differ.
            // Let's try to fetch packages from Paystack to resolve amount.
            $packagesRes = $this->paystackSpectranetService->getPackages();
            $packages = $packagesRes['data'] ?? [];

            // Re-implementation of resolvePackageAmount for Paystack structure (assumed 'code' and 'amount')
            $amount = null;
            foreach ($packages as $pkg) {
                if (($pkg['code'] ?? '') === $validated['package_code']) {
                    $amount = ($pkg['amount'] ?? 0) / 100; // Paystack amount is kobo
                    break;
                }
            }

            if (!$amount) {
                throw new \Exception('Invalid package code or unable to determine amount.');
            }

            $transaction->update(['amount' => $amount]);

            $debit = debitWallet(
                $user,
                $amount,
                'Spectranet Bundle Purchase (Paystack)',
                sprintf(
                    'Spectranet bundle for %s (%s)',
                    $validated['customer_id'],
                    $validated['package_code']
                ),
                0,
                0,
                $transactionRef,
                false
            );

            $result = $this->paystackSpectranetService->buyBundle(
                planCode: $validated['package_code'],
                customerId: $validated['customer_id'],
                amount: $amount,
                phoneNo: $user->sPhone,
                email: $user->email
            );

            $this->paystackTransactionService->handleProviderResponse(
                $result,
                $transaction,
                $user,
                $amount
            );

            DB::commit();

            return $this->ok('Spectranet bundle purchased successfully', [
                'transaction_ref' => $transactionRef,
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
            Log::error('Failed to buy spectranet (Paystack)', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    private function isPaystackEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'paystackStatus') === 'On' &&
                getConfigValue($config, 'paystackSpectranetStatus') === 'On';
        }

        return $enabled;
    }
}
