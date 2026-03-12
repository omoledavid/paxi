<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\NelloBytes\PrintEpinRequest;
use App\Mail\SendEpin;
use App\Models\ApiConfig;
use App\Models\Epin;
use App\Models\EpinPrice;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\EpinService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Services\ReferralBonusService;
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
            $user = auth()->user();
            $role = $user ? (int) $user->sType : 0;

            // Load all EPIN prices from DB, grouped by network
            $allPrices = EpinPrice::orderBy('network_id')->orderBy('amount')->get();
            $grouped = $allPrices->groupBy('network_id');

            $formattedDiscounts = [];

            foreach ($grouped as $networkId => $prices) {
                $networkPrices = [];
                foreach ($prices as $priceRecord) {
                    $payable = match ($role) {
                        2 => $priceRecord->agent_price,
                        3 => $priceRecord->vendor_price,
                        default => $priceRecord->user_price,
                    };
                    $networkPrices[] = [
                        'amount' => $priceRecord->amount,
                        'payable' => $payable,
                    ];
                }

                $formattedDiscounts[] = [
                    'network' => $prices->first()->network_name,
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
            $role = (int) $user->sType;

            // Apply discount from DB-stored per-role pricing
            $amount = $this->applyProductDiscount(
                $originalAmount,
                $validated['mobile_network'],
                (int) $validated['value'],
                (int) $validated['quantity'],
                $role
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

            ReferralBonusService::credit($user, $amount, ReferralBonusService::AIRTIME, $transactionRef);

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
     * Apply product discount to amount based on mobile network and user role.
     * Reads per-denomination prices from epin_prices table.
     *
     * @param  float  $amount  The original total amount (value * quantity)
     * @param  string  $networkId  The mobile network ID (01, 02, 03, 04)
     * @param  int  $value  The single denomination value (100, 200, 500)
     * @param  int  $quantity  Number of EPINs
     * @param  int  $role  User role (0=User, 2=Agent, 3=Vendor)
     * @return float The discounted total amount
     */
    private function applyProductDiscount(float $amount, string $networkId, int $value = 0, int $quantity = 1, int $role = 0): float
    {
        if ($value > 0) {
            $payablePerUnit = EpinPrice::getPayablePrice($networkId, $value, $role);
            $discountedAmount = $payablePerUnit * $quantity;

            Log::info('EPIN discount applied from DB', [
                'network_id' => $networkId,
                'value' => $value,
                'quantity' => $quantity,
                'role' => $role,
                'payable_per_unit' => $payablePerUnit,
                'discounted_total' => $discountedAmount,
            ]);

            return $discountedAmount;
        }

        // Fallback: use discount rate for the total amount
        $rate = EpinPrice::getDiscountRate($networkId, (int) $amount, $role);
        $discountedAmount = $amount * $rate;

        Log::info('EPIN discount applied (rate fallback)', [
            'network_id' => $networkId,
            'original_amount' => $amount,
            'rate' => $rate,
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
