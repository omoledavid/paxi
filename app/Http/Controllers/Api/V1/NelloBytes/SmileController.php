<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\BuySmileBundleRequest;
use App\Http\Requests\NelloBytes\VerifySmileRequest;
use App\Models\NelloBytesTransaction;
use App\Models\PaystackTransaction; // [NEW]
use App\Models\ApiConfig;
use App\Services\NelloBytes\SmileService;
use App\Services\Paystack\PaystackTransactionService; // [NEW]
// Assuming needed or was implicit?
use App\Services\Paystack\SmileService as PaystackSmileService; // [NEW]
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class SmileController extends Controller
{
    use ApiResponses;

    protected SmileService $smileService;

    protected PaystackSmileService $paystackSmileService; // [NEW]

    protected PaystackTransactionService $paystackTransactionService; // [NEW]

    public function __construct(
        SmileService $smileService,
        PaystackSmileService $paystackSmileService,
        PaystackTransactionService $paystackTransactionService
    ) {
        $this->smileService = $smileService;
        $this->paystackSmileService = $paystackSmileService;
        $this->paystackTransactionService = $paystackTransactionService;
    }

    /**
     * Get Smile packages
     */
    public function getPackages(): JsonResponse
    {
        try {
            if ($this->isPaystackEnabled()) {
                $response = $this->paystackSmileService->getPackages();
                if (isset($response['data'])) {
                    $packages = collect($response['data'])->map(function ($item) {
                        return $item;
                    });
                }

                return $this->ok('Smile packages retrieved successfully', $response);
            }
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
     */
    public function verify(VerifySmileRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $mobileNetwork = $validated['mobile_network'] ?? config('nellobytes.smile_default_network', 'smile-direct');
            $mobileNumber = $validated['mobile_number'] ?? $validated['customer_id'];

            if ($this->isPaystackEnabled()) {
                // Smile verification via Paystack
                $result = $this->paystackSmileService->verifyCustomer($mobileNumber);

                return $this->ok('Customer verified successfully', $result);
            }

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
     */
    public function buyBundle(BuySmileBundleRequest $request): JsonResponse
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

    private function buyBundlePaystack($validated, $user)
    {
        $transactionRef = generateTransactionRef();

        $transaction = PaystackTransaction::create([
            'user_id' => $user->sId,
            'service_type' => \App\Enums\PaystackServiceType::SMILE,
            'transaction_ref' => $transactionRef,
            'amount' => 0, // Placeholder
            'status' => TransactionStatus::PENDING,
            'request_payload' => $validated,
        ]);

        DB::beginTransaction();
        try {
            // Fetch amount from Paystack if possible
            $packagesRes = $this->paystackSmileService->getPackages();
            $packages = $packagesRes['data'] ?? [];

            $amount = null;
            foreach ($packages as $pkg) {
                if (($pkg['code'] ?? '') === $validated['package_code']) {
                    $amount = ($pkg['amount'] ?? 0) / 100;
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
                'Smile Bundle Purchase (Paystack)',
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

            $mobileNumber = $validated['mobile_number'] ?? $validated['customer_id'];

            $result = $this->paystackSmileService->buyBundle(
                planCode: $validated['package_code'],
                customerId: $mobileNumber,
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

            return $this->ok('Smile bundle purchased successfully', [
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
            Log::error('Failed to buy smile (Paystack)', ['error' => $e->getMessage()]);

            return $this->error($e->getMessage(), 500);
        }
    }

    private function isPaystackEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'paystackStatus') === 'On' &&
                getConfigValue($config, 'paystackSmileStatus') === 'On';
        }

        return $enabled;
    }
}
