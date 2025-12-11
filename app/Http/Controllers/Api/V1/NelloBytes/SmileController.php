<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\BuySmileBundleRequest;
use App\Http\Requests\NelloBytes\VerifySmileRequest;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\SmileService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class SmileController extends Controller
{
    use ApiResponses;

    protected SmileService $smileService;

    public function __construct(SmileService $smileService)
    {
        $this->smileService = $smileService;
    }

    /**
     * Get Smile packages
     *
     * @return JsonResponse
     */
    public function getPackages(): JsonResponse
    {
        try {
            $packages = $this->smileService->getPackages();

            return $this->ok('Smile packages retrieved successfully', $packages);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve Smile packages', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Failed to retrieve Smile packages', 500, $e->getMessage());
        }
    }

    /**
     * Verify Smile customer
     *
     * @param VerifySmileRequest $request
     * @return JsonResponse
     */
    public function verify(VerifySmileRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $mobileNetwork = $validated['mobile_network'] ?? config('nellobytes.smile_default_network', 'smile-direct');
            $mobileNumber = $validated['mobile_number'] ?? $validated['customer_id'];

            $result = $this->smileService->verify($mobileNetwork, $mobileNumber);

            return $this->ok('Customer verified successfully', $result);
        } catch (\Exception $e) {
            Log::error('Failed to verify Smile customer', [
                'request' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $statusCode = $e instanceof \RuntimeException
                ? 400
                : (method_exists($e, 'getCode') && $e->getCode() > 0 ? $e->getCode() : 500);

            return $this->error($e->getMessage(), $statusCode);
        }
    }

    /**
     * Buy Smile bundle
     *
     * @param BuySmileBundleRequest $request
     * @return JsonResponse
     */
    public function buyBundle(BuySmileBundleRequest $request): JsonResponse
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

        $packages = $this->smileService->getPackages();
        $amount = $this->resolvePackageAmount($packages, $validated['package_code']);

        if ($amount === null) {
            return $this->error('Unable to determine package amount. Please try again.');
        }

        $mobileNetwork = $validated['mobile_network'] ?? config('nellobytes.smile_default_network', 'smile-direct');
        $mobileNumber = $validated['mobile_number'] ?? $validated['customer_id'];
        $callbackUrl = config('nellobytes.smile_callback_url') ?: URL::to('/webhooks/nellobytes');

        // Create transaction record
        $transaction = NelloBytesTransaction::create([
            'user_id' => $user->sId,
            'service_type' => NelloBytesServiceType::SMILE,
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
                'Smile Bundle Purchase',
                sprintf(
                    'Smile bundle for %s (%s)',
                    $validated['customer_id'],
                    $validated['package_code']
                ),
                0,
                0,
                $transactionRef,
                false
            );

            $result = $this->smileService->buyBundle(
                $mobileNetwork,
                $validated['package_code'],
                $mobileNumber,
                $transactionRef,
                $callbackUrl
            );

            // Update transaction with response
            $amountFromResponse = $result['amount'] ?? $result['price'] ?? $amount;
            $delta = $amountFromResponse - $amount;
            $finalBalance = $debit['new_balance'];

            // Reconcile wallet if API returned a different amount
            if (abs($delta) > 0) {
                if ($delta > 0) {
                    // Additional debit required
                    $debit = debitWallet(
                        $debit['user'],
                        $delta,
                        'Smile Bundle Purchase Adjustment',
                        sprintf(
                            'Additional debit for Smile bundle %s (%s)',
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
                    // Credit back excess debit
                    $credit = creditWallet(
                        $debit['user'],
                        abs($delta),
                        'Smile Bundle Purchase Adjustment',
                        sprintf(
                            'Refund adjustment for Smile bundle %s (%s)',
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

            return $this->ok('Smile bundle purchased successfully', [
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

            Log::error('Failed to buy Smile bundle', [
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

