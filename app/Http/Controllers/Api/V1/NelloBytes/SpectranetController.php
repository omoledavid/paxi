<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\BuySpectranetBundleRequest;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\SpectranetService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SpectranetController extends Controller
{
    use ApiResponses;

    protected SpectranetService $spectranetService;

    public function __construct(SpectranetService $spectranetService)
    {
        $this->spectranetService = $spectranetService;
    }

    /**
     * Get Spectranet packages
     *
     * @return JsonResponse
     */
    public function getPackages(): JsonResponse
    {
        try {
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
     *
     * @param BuySpectranetBundleRequest $request
     * @return JsonResponse
     */
    public function buyBundle(BuySpectranetBundleRequest $request): JsonResponse
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
}

