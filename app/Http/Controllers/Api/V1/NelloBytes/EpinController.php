<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\PrintEpinRequest;
use App\Mail\SendEpin;
use App\Models\ApiConfig;
use App\Models\Epin;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\EpinService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Traits\ApiResponses;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EpinController extends Controller
{
    use ApiResponses;

    protected EpinService $epinService;

    protected NelloBytesTransactionService $nelloBytesTransactionService;

    public function __construct(EpinService $epinService, NelloBytesTransactionService $nelloBytesTransactionService)
    {
        $this->epinService = $epinService;
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
    }

    /**
     * Get EPIN discounts
     */
    public function getDiscounts(): JsonResponse
    {
        try {
            // We now use hardcoded rates in applyProductDiscount for the supported networks,
            // so we don't need to fetch external discounts here.
            $discounts = [];
            $prices = [100, 200, 500];
            $networkIds = ['01', '02', '03', '04'];

            $formattedDiscounts = [];
            $networkMap = [
                '01' => 'MTN',
                '02' => 'Glo',
                '03' => 'T2-Mobile',
                '04' => 'Airtel',
            ];

            foreach ($networkIds as $networkId) {
                $networkPrices = [];
                foreach ($prices as $price) {
                    $discountedAmount = $this->applyProductDiscount((float) $price, $networkId, $discounts);
                    $networkPrices[] = [
                        'amount' => $price,
                        'payable' => $discountedAmount,
                    ];
                }

                $formattedDiscounts[] = [
                    'network' => $networkMap[$networkId] ?? 'Unknown',
                    'network_id' => $networkId,
                    'prices' => $networkPrices,
                ];
            }

            return $this->ok('EPIN discounts retrieved successfully', [
                'discounts' => $formattedDiscounts,
            ]);
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
     */
    public function printCard(PrintEpinRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        if (!$this->isNellobytesEnabled()) {
            return $this->error('Epin is currently unavailable', 400);
        }

        // Verify PIN
        if ($user->sPin != $validated['pin']) {
            return $this->error('Incorrect PIN', 400);
        }

        // Generate transaction reference (used as RequestID)
        $transactionRef = generateTransactionRef();

        $originalAmount = (float) ($validated['value'] * $validated['quantity']);

        // Remove sensitive data from request payload before storing
        $requestPayload = $validated;
        unset($requestPayload['pin']);

        DB::beginTransaction();
        try {
            // Fetch discounts first
            $discounts = $this->epinService->getDiscounts();

            // Apply discount to get the final amount to charge
            $amount = $this->applyProductDiscount(
                $originalAmount,
                $validated['mobile_network'],
                $discounts ?? []
            );

            // Create transaction record with discounted amount
            $transaction = NelloBytesTransaction::create([
                'user_id' => $user->sId,
                'service_type' => NelloBytesServiceType::EPIN,
                'transaction_ref' => $transactionRef,
                'amount' => $amount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $requestPayload,
            ]);

            $networks = [
                '01' => 'MTN',
                '02' => 'GLO',
                '04' => 'Airtel',
                '03' => 'T2-Mobile',
            ];

            // Debit wallet with discounted amount
            $debit = debitWallet(
                $user,
                $amount,
                'EPIN Purchase',
                sprintf(
                    'EPIN %s x %s for %s',
                    number_format($validated['value'], 2),
                    $validated['quantity'],
                    $networks[$validated['mobile_network']] ?? $validated['mobile_network']
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

            // Handle provider response - this will update transaction status, nellobytes_ref, and handle refunds if needed
            $this->nelloBytesTransactionService->handleProviderResponse(
                $result,
                $transaction,
                $user,
                $amount
            );

            // Save EPINs to database
            $savedEpins = [];
            if (isset($result['TXN_EPIN']) && is_array($result['TXN_EPIN'])) {
                foreach ($result['TXN_EPIN'] as $pinData) {
                    $epinData = [
                        'user_id' => $user->sId,
                        'transaction_id' => $transaction->id,
                        'network' => $validated['mobile_network'],
                        'amount' => $validated['value'],
                        'pin_code' => $pinData['pin'] ?? $pinData['CardPin'] ?? 'UNKNOWN',
                        'serial_number' => $pinData['sno'] ?? $pinData['SerialNo'] ?? null,
                        'expiry_date' => isset($pinData['expiry']) ? Carbon::parse($pinData['expiry']) : null,
                        'status' => 'unused',
                        'description' => "Purchased {$validated['mobile_network']} {$validated['value']}",
                    ];
                    Epin::create($epinData);
                    $savedEpins[] = $epinData;
                }
            }

            DB::commit();

            // Send email
            try {
                Mail::to($user->sEmail)->send(new SendEpin($savedEpins, $transactionRef));
            } catch (\Exception $e) {
                Log::error('Failed to send EPIN email', ['error' => $e->getMessage()]);
            }

            // Refresh transaction to get updated nellobytes_ref
            $transaction->refresh();

            return $this->ok('EPIN card printed successfully', [
                'transaction_ref' => $transactionRef,
                'nellobytes_ref' => $transaction->nellobytes_ref,
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

            $statusCode = 500;
            if (method_exists($e, 'getCode')) {
                $code = (int) $e->getCode();
                if ($code >= 400 && $code < 600) {
                    $statusCode = $code;
                }
            }

            return $this->error($e->getMessage(), $statusCode);
        }
    }

    /**
     * Query EPIN transaction by RequestID or OrderID
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

            $statusCode = 500;
            if ($e instanceof \RuntimeException) {
                $statusCode = 400;
            } elseif (method_exists($e, 'getCode')) {
                $code = (int) $e->getCode();
                if ($code >= 400 && $code < 600) {
                    $statusCode = $code;
                }
            }

            return $this->error($e->getMessage(), $statusCode);
        }
    }

    /**
     * Get EPIN purchase history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->input('limit', 20);

        $epins = Epin::where('user_id', $user->sId)
            ->latest()
            ->paginate($limit)
            ->through(function ($epin) {
                $networks = [
                    '01' => 'MTN',
                    '02' => 'GLO',
                    '04' => 'Airtel',
                    '03' => 'T2-Mobile',
                ];

                if (isset($networks[$epin->network])) {
                    $epin->network = $networks[$epin->network];
                }

                return $epin;
            });

        return $this->ok('EPIN history retrieved', $epins);
    }

    /**
     * Apply product discount to amount based on mobile network
     *
     * @param  float  $amount  The original amount
     * @param  string  $networkId  The mobile network ID (01, 02, 03, 04)
     * @param  array  $discountData  The discount data from API
     * @return float The discounted amount
     */
    private function applyProductDiscount(float $amount, string $networkId, array $discountData): float
    {
        // Hardcoded rates based on user request:
        // MTN: 100->100 (1.0)
        // Glo: 100->99 (0.99)
        // Airtel: 100->98 (0.98)
        // T2mobile: 100->96 (0.96)
        $customRates = [
            '01' => 1.0,   // MTN
            '02' => 0.99,  // Glo
            '04' => 0.98,  // Airtel
            '03' => 0.96,  // T2-Mobile
        ];

        if (array_key_exists($networkId, $customRates)) {
            $discountedAmount = $amount * $customRates[$networkId];
            Log::info('Custom discount applied', [
                'network_id' => $networkId,
                'original_amount' => $amount,
                'rate' => $customRates[$networkId],
                'discounted_amount' => $discountedAmount,
            ]);

            return $discountedAmount;
        }

        // Fallback to original logic if network ID isn't in our custom list (unlikely given the known IDs)
        // Map network IDs to network names
        $networkMap = [
            '01' => 'MTN',
            '02' => 'Glo',
            '03' => 'T2-Mobile',
            '04' => 'Airtel',
        ];

        // Get the network name from the ID
        $networkName = $networkMap[$networkId] ?? null;

        if (!$networkName) {
            // If network not found, return original amount
            Log::warning('Unknown network ID for discount calculation', ['network_id' => $networkId]);

            return $amount;
        }

        // Check if discount data exists for this network
        if (!isset($discountData['MOBILE_NETWORK'][$networkName])) {
            Log::warning('No discount data found for network', ['network' => $networkName]);

            return $amount;
        }

        $networkDiscounts = $discountData['MOBILE_NETWORK'][$networkName];

        // Get the first discount entry (assuming one discount per network)
        if (empty($networkDiscounts) || !isset($networkDiscounts[0]['PRODUCT_DISCOUNT_AMOUNT'])) {
            Log::warning('Invalid discount structure for network', ['network' => $networkName]);

            return $amount;
        }

        $discountMultiplier = (float) $networkDiscounts[0]['PRODUCT_DISCOUNT_AMOUNT'];

        // Calculate and return discounted amount
        $discountedAmount = $amount * $discountMultiplier;

        Log::info('Discount applied', [
            'network' => $networkName,
            'original_amount' => $amount,
            'discount_multiplier' => $discountMultiplier,
            'discount_percentage' => $networkDiscounts[0]['PRODUCT_DISCOUNT'] ?? 'N/A',
            'discounted_amount' => $discountedAmount,
        ]);

        return $discountedAmount;
    }

    private function isNellobytesEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'nellobytesStatus') === 'On' &&
                getConfigValue($config, 'nellobytesRechargeStatus') === 'On';
        }

        return $enabled;
    }
}
