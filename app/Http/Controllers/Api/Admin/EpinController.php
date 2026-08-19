<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiConfig;
use App\Models\Epin;
use App\Models\EpinPrice;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\EpinService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Traits\ApiResponses;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EpinController extends Controller
{
    use ApiResponses;

    /**
     * Sources that count as admin-owned inventory: bought from the provider in bulk,
     * or entered/scanned in by hand from physical slips.
     */
    public const ADMIN_SOURCES = ['admin_bulk', 'admin_manual'];

    public const MANUAL_SOURCE = 'admin_manual';

    /** Ceiling on one manual submission, matching the review table's practical size. */
    public const MAX_MANUAL_PINS = 200;

    protected EpinService $epinService;

    protected NelloBytesTransactionService $nelloBytesTransactionService;

    public function __construct(EpinService $epinService, NelloBytesTransactionService $nelloBytesTransactionService)
    {
        $this->epinService = $epinService;
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
    }

    /**
     * Bulk-purchase EPINs from NelloBytes and store in local inventory.
     */
    public function buyBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mobile_network' => 'required|string|in:01,02,03,04',
            'value' => 'required|integer|in:100,200,500,1000',
            'quantity' => 'required|integer|min:1|max:100',
            'admin_id' => 'nullable|integer',
        ]);

        $totalAmount = (float) ($validated['value'] * $validated['quantity']);

        $walletCheck = checkServiceWallet('nellobytes', $totalAmount);
        if ($walletCheck['status'] !== 'success' || ! $walletCheck['has_sufficient']) {
            return $this->error(
                'Insufficient NelloBytes wallet balance. Available: '.
                ($walletCheck['balance'] ?? 0).', Required: '.$totalAmount,
                402,
                [
                    'balance' => $walletCheck['balance'] ?? 0,
                    'required' => $totalAmount,
                ]
            );
        }

        $transactionRef = generateTransactionRef();
        $batchReference = 'BATCH-'.date('Ymd').'-'.strtoupper(substr(md5($transactionRef), 0, 8));

        DB::beginTransaction();
        try {
            $transaction = NelloBytesTransaction::create([
                'user_id' => 0,
                'service_type' => NelloBytesServiceType::EPIN,
                'transaction_ref' => $transactionRef,
                'amount' => $totalAmount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => [
                    'mobile_network' => $validated['mobile_network'],
                    'value' => $validated['value'],
                    'quantity' => $validated['quantity'],
                    'batch_reference' => $batchReference,
                    'source' => 'admin_bulk',
                ],
            ]);

            $result = $this->epinService->buyAirtimeEpin(
                $validated['mobile_network'],
                (int) $validated['value'],
                (int) $validated['quantity'],
                $transactionRef
            );

            // No user wallet involved — skip the handleProviderResponse refund logic
            $txnPins = $result['TXN_EPIN'] ?? [];

            if (empty($txnPins) || ! is_array($txnPins)) {
                $transaction->update([
                    'status' => TransactionStatus::FAILED,
                    'response_payload' => $result,
                    'error_message' => $result['message'] ?? 'No EPIN data returned from provider',
                ]);
                DB::rollBack();

                return $this->error(
                    $result['message'] ?? 'No EPIN data returned from provider',
                    500
                );
            }

            $nellobytesRef = $txnPins[0]['transactionid'] ?? null;
            $unitCost = $result['cost'] ?? $result['unit_price'] ?? $validated['value'];

            $savedEpins = [];
            foreach ($txnPins as $pinIndex => $pinData) {
                $epin = Epin::create([
                    'user_id' => null,
                    'transaction_id' => $transaction->id,
                    'network' => $validated['mobile_network'],
                    'amount' => $validated['value'],
                    'pin_code' => $pinData['pin'] ?? $pinData['CardPin'] ?? "UNKNOWN-{$transactionRef}-{$pinIndex}",
                    'serial_number' => $pinData['sno'] ?? $pinData['SerialNo'] ?? null,
                    'expiry_date' => isset($pinData['expiry']) ? Carbon::parse($pinData['expiry']) : null,
                    'status' => 'unused',
                    'description' => "Admin bulk purchase — {$validated['mobile_network']} {$validated['value']}",
                    'source' => 'admin_bulk',
                    'batch_reference' => $batchReference,
                    'purchase_cost' => $unitCost,
                    'purchased_by_admin_id' => $validated['admin_id'] ?? null,
                ]);
                $savedEpins[] = $epin;
            }

            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $result,
            ]);

            DB::commit();

            Log::info('Admin bulk EPIN purchase completed', [
                'batch_reference' => $batchReference,
                'transaction_ref' => $transactionRef,
                'network' => $validated['mobile_network'],
                'value' => $validated['value'],
                'quantity' => $validated['quantity'],
                'pin_count' => count($savedEpins),
            ]);

            return $this->ok('EPINs purchased and stored successfully', [
                'batch_reference' => $batchReference,
                'transaction_ref' => $transactionRef,
                'nellobytes_ref' => $nellobytesRef,
                'pin_count' => count($savedEpins),
                'unit_cost' => (float) $unitCost,
                'total_cost' => (float) $unitCost * count($savedEpins),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            if (isset($transaction)) {
                $transaction->update([
                    'status' => TransactionStatus::FAILED,
                    'error_message' => $e->getMessage(),
                    'error_code' => method_exists($e, 'getErrorCode') ? $e->getErrorCode() : null,
                    'response_payload' => ['error' => $e->getMessage()],
                ]);
            }

            Log::error('Admin bulk EPIN purchase failed', [
                'transaction_ref' => $transactionRef ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            $statusCode = 500;
            if (method_exists($e, 'getCode')) {
                $code = (int) $e->getCode();
                if ($code >= 400 && $code < 600) {
                    $statusCode = $code;
                }
            }

            return $this->error(
                $e->getMessage().' Use the Sync button on the batch to recover PINs if NelloBytes already processed the order.',
                $statusCode,
                ['batch_reference' => $batchReference ?? null, 'transaction_ref' => $transactionRef ?? null]
            );
        }
    }

    /**
     * Get aggregate EPIN inventory stats.
     */
    public function stats(): JsonResponse
    {
        $totalPurchased = Epin::whereIn('source', self::ADMIN_SOURCES)->count();
        $available = Epin::whereIn('source', self::ADMIN_SOURCES)
            ->whereNull('user_id')
            ->where('status', 'unused')
            ->count();
        $disbursed = Epin::whereIn('source', self::ADMIN_SOURCES)
            ->whereNotNull('user_id')
            ->count();
        $totalSpent = (float) Epin::whereIn('source', self::ADMIN_SOURCES)
            ->sum('purchase_cost');

        $byNetwork = Epin::whereIn('source', self::ADMIN_SOURCES)
            ->selectRaw('network, COUNT(*) as count')
            ->groupBy('network')
            ->get()
            ->keyBy('network')
            ->toArray();

        $byNetworkAvailable = Epin::whereIn('source', self::ADMIN_SOURCES)
            ->whereNull('user_id')
            ->where('status', 'unused')
            ->selectRaw('network, COUNT(*) as count')
            ->groupBy('network')
            ->get()
            ->keyBy('network')
            ->toArray();

        return $this->ok('EPIN stats retrieved', [
            'total_purchased' => $totalPurchased,
            'available' => $available,
            'disbursed' => $disbursed,
            'total_spent' => $totalSpent,
            'by_network' => $byNetwork,
            'by_network_available' => $byNetworkAvailable,
        ]);
    }

    /**
     * Paginated, filterable EPIN inventory list.
     */
    public function inventory(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = Epin::query()->whereIn('source', self::ADMIN_SOURCES);

        if ($request->filled('network')) {
            $query->where('network', $request->input('network'));
        }

        if ($request->filled('status')) {
            if ($request->input('status') === 'available') {
                $query->whereNull('user_id')->where('status', 'unused');
            } elseif ($request->input('status') === 'disbursed') {
                $query->whereNotNull('user_id');
            } elseif ($request->input('status') === 'used') {
                $query->where('status', 'used');
            }
        }

        if ($request->filled('batch_reference')) {
            $query->where('batch_reference', $request->input('batch_reference'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $epins = $query->with('user:subscribers.sId,sName,sEmail,sMNumber')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->ok('EPIN inventory retrieved', $epins);
    }

    /**
     * List bulk batch purchase history.
     */
    public function batches(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $batches = Epin::whereIn('source', self::ADMIN_SOURCES)
            ->selectRaw("
                batch_reference,
                network,
                amount,
                source,
                COUNT(*) as pin_count,
                SUM(purchase_cost) as total_cost,
                MIN(created_at) as purchased_at,
                SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as disbursed_count,
                SUM(CASE WHEN user_id IS NULL AND status = 'unused' THEN 1 ELSE 0 END) as available_count
            ")
            ->groupBy('batch_reference', 'network', 'amount', 'source')
            ->orderBy('purchased_at', 'desc')
            ->paginate($perPage);

        return $this->ok('EPIN batches retrieved', $batches);
    }

    /**
     * Sync/recover a failed batch by querying NelloBytes for the original transaction.
     */
    public function syncBatch(Request $request, string $batchRef): JsonResponse
    {
        $existingPins = Epin::where('batch_reference', $batchRef)->get();
        if ($existingPins->isEmpty()) {
            return $this->error('Batch not found', 404);
        }

        $transaction = NelloBytesTransaction::where('transaction_ref', function ($q) {
            // Batch ref is BATCH-YYYYMMDD-XXXXXXXX, transaction_ref is the original ref
            // We stored it in request_payload — find the matching transaction
        })->first();

        $firstPin = $existingPins->first();
        $transaction = $firstPin->transaction_id
            ? NelloBytesTransaction::find($firstPin->transaction_id)
            : null;

        if (! $transaction) {
            return $this->error('Original transaction not found for this batch', 404);
        }

        $transactionRef = $transaction->transaction_ref;

        try {
            $result = $this->epinService->queryTransaction($transactionRef);

            $txnPins = $result['TXN_EPIN'] ?? $result['data']['TXN_EPIN'] ?? [];

            if (empty($txnPins) || ! is_array($txnPins)) {
                return $this->error('No PINs to recover — the purchase was never completed by NelloBytes', 404);
            }

            $savedPinCodes = Epin::whereIn('pin_code', collect($txnPins)
                ->map(fn ($p) => $p['pin'] ?? $p['CardPin'] ?? null)
                ->filter()
                ->all())->pluck('pin_code')->toArray();
            $recovered = 0;

            $network = $existingPins->first()->network;
            $amount = $existingPins->first()->amount;
            $purchaseCost = $existingPins->first()->purchase_cost;
            $adminId = $existingPins->first()->purchased_by_admin_id;

            foreach ($txnPins as $pinData) {
                $pinCode = $pinData['pin'] ?? $pinData['CardPin'] ?? null;
                if (! $pinCode || in_array($pinCode, $savedPinCodes)) {
                    continue;
                }

                Epin::create([
                    'user_id' => null,
                    'transaction_id' => $transaction->id,
                    'network' => $network,
                    'amount' => $amount,
                    'pin_code' => $pinCode,
                    'serial_number' => $pinData['sno'] ?? $pinData['SerialNo'] ?? null,
                    'expiry_date' => isset($pinData['expiry']) ? Carbon::parse($pinData['expiry']) : null,
                    'status' => 'unused',
                    'description' => "Recovered via sync — batch {$batchRef}",
                    'source' => 'admin_bulk',
                    'batch_reference' => $batchRef,
                    'purchase_cost' => $purchaseCost,
                    'purchased_by_admin_id' => $adminId,
                ]);
                $recovered++;
            }

            if ($transaction->status === TransactionStatus::FAILED) {
                $transaction->update([
                    'status' => TransactionStatus::SUCCESS,
                    'response_payload' => $result,
                ]);
            }

            Log::info('Batch synced successfully', [
                'batch_reference' => $batchRef,
                'recovered' => $recovered,
            ]);

            return $this->ok('Batch synced successfully', [
                'batch_reference' => $batchRef,
                'recovered' => $recovered,
                'total_pins' => count($txnPins),
            ]);
        } catch (\Exception $e) {
            Log::error('Batch sync failed', [
                'batch_reference' => $batchRef,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to sync batch: '.$e->getMessage(), 500);
        }
    }

    /**
     * Add EPINs to inventory from manual entry or scanned physical slips.
     *
     * Pins arrive already reviewed by an admin — the scan UI never posts straight
     * from OCR — so this is deliberately a plain insert with duplicate rejection
     * rather than anything that talks to NelloBytes.
     */
    public function manualAdd(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pins' => 'required|array|min:1|max:'.self::MAX_MANUAL_PINS,
            'pins.*.pin_code' => 'required|string|max:64',
            'pins.*.serial_number' => 'nullable|string|max:64',
            'pins.*.network' => 'required|string|in:01,02,03,04',
            'pins.*.amount' => 'required|integer|min:1',
            'pins.*.purchase_cost' => 'nullable|numeric|min:0',
            'pins.*.printed_at' => 'nullable|date',
            'pins.*.note' => 'nullable|string|max:191',
            'entry_method' => 'nullable|string|in:manual,scan',
            'admin_id' => 'nullable|integer',
        ]);

        $entryMethod = $validated['entry_method'] ?? 'manual';
        $adminId = $validated['admin_id'] ?? null;
        $allowedAmounts = $this->allowedDenominations();

        $batchReference = 'MANUAL-'.date('Ymd').'-'.strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $prepared = [];
        $skipped = [];
        $seen = [];

        foreach ($validated['pins'] as $index => $pin) {
            $pinCode = $this->normalizePinCode($pin['pin_code']);
            $label = $pin['pin_code'];

            if ($pinCode === '') {
                $skipped[] = ['row' => $index + 1, 'pin_code' => $label, 'reason' => 'Empty PIN code'];

                continue;
            }

            if (! in_array((int) $pin['amount'], $allowedAmounts, true)) {
                $skipped[] = [
                    'row' => $index + 1,
                    'pin_code' => $label,
                    'reason' => 'Unsupported denomination: '.$pin['amount'],
                ];

                continue;
            }

            if (isset($seen[$pinCode])) {
                $skipped[] = [
                    'row' => $index + 1,
                    'pin_code' => $label,
                    'reason' => 'Duplicate of row '.$seen[$pinCode].' in this submission',
                ];

                continue;
            }

            $seen[$pinCode] = $index + 1;
            $prepared[$pinCode] = [
                'row' => $index + 1,
                'label' => $label,
                'pin_code' => $pinCode,
                'serial_number' => $this->normalizePinCode($pin['serial_number'] ?? '') ?: null,
                'network' => $pin['network'],
                'amount' => (int) $pin['amount'],
                'purchase_cost' => isset($pin['purchase_cost']) ? (float) $pin['purchase_cost'] : (float) $pin['amount'],
                'printed_at' => ! empty($pin['printed_at']) ? Carbon::parse($pin['printed_at']) : null,
                'note' => $pin['note'] ?? null,
            ];
        }

        if (empty($prepared)) {
            return $this->error('No valid PINs to add.', 422, ['skipped' => $skipped]);
        }

        $existing = Epin::whereIn('pin_code', array_keys($prepared))->pluck('pin_code')->all();
        foreach ($existing as $pinCode) {
            if (! isset($prepared[$pinCode])) {
                continue;
            }
            $skipped[] = [
                'row' => $prepared[$pinCode]['row'],
                'pin_code' => $prepared[$pinCode]['label'],
                'reason' => 'Already in inventory',
            ];
            unset($prepared[$pinCode]);
        }

        if (empty($prepared)) {
            return $this->error('Every PIN in this batch is already in inventory.', 409, ['skipped' => $skipped]);
        }

        $inserted = 0;
        $warnings = [];

        try {
            DB::transaction(function () use ($prepared, $batchReference, $entryMethod, $adminId, &$inserted) {
                foreach ($prepared as $pin) {
                    $description = $entryMethod === 'scan'
                        ? "Scanned slip — batch {$batchReference}"
                        : "Manual entry — batch {$batchReference}";

                    if (! empty($pin['note'])) {
                        $description .= ' | '.$pin['note'];
                    }

                    Epin::create([
                        'user_id' => null,
                        'transaction_id' => null,
                        'network' => $pin['network'],
                        'amount' => $pin['amount'],
                        'pin_code' => $pin['pin_code'],
                        'serial_number' => $pin['serial_number'],
                        'expiry_date' => null,
                        'printed_at' => $pin['printed_at'],
                        'status' => 'unused',
                        'description' => $description,
                        'source' => self::MANUAL_SOURCE,
                        'batch_reference' => $batchReference,
                        'purchase_cost' => $pin['purchase_cost'],
                        'purchased_by_admin_id' => $adminId,
                        'entry_method' => $entryMethod,
                    ]);
                    $inserted++;
                }
            });
        } catch (\Exception $e) {
            Log::error('Manual EPIN add failed', [
                'batch_reference' => $batchReference,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to save PINs: '.$e->getMessage(), 500);
        }

        // Stock nobody can buy is worth flagging: dispensing prices off epin_prices.
        foreach ($prepared as $pin) {
            $key = $pin['network'].':'.$pin['amount'];
            if (isset($warnings[$key])) {
                continue;
            }
            $hasPrice = EpinPrice::where('network_id', $pin['network'])
                ->where('amount', $pin['amount'])
                ->exists();
            if (! $hasPrice) {
                $warnings[$key] = "No price is configured for network {$pin['network']} at N{$pin['amount']} — users cannot buy this stock yet.";
            }
        }

        Log::info('Manual EPIN add completed', [
            'batch_reference' => $batchReference,
            'entry_method' => $entryMethod,
            'inserted' => $inserted,
            'skipped' => count($skipped),
            'admin_id' => $adminId,
        ]);

        return $this->ok('EPINs added to inventory', [
            'batch_reference' => $batchReference,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'warnings' => array_values($warnings),
        ]);
    }

    /**
     * Report which of the supplied PIN codes are already in inventory.
     *
     * Lets the review table flag duplicates before the admin commits a batch,
     * rather than reporting them after the fact.
     */
    public function checkDuplicates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pin_codes' => 'required|array|min:1|max:'.self::MAX_MANUAL_PINS,
            'pin_codes.*' => 'required|string|max:64',
        ]);

        $normalized = [];
        foreach ($validated['pin_codes'] as $raw) {
            $clean = $this->normalizePinCode($raw);
            if ($clean !== '') {
                $normalized[$clean] = $raw;
            }
        }

        if (empty($normalized)) {
            return $this->ok('No PIN codes to check', ['duplicates' => []]);
        }

        $existing = Epin::whereIn('pin_code', array_keys($normalized))
            ->pluck('pin_code')
            ->all();

        $duplicates = [];
        foreach ($existing as $pinCode) {
            if (isset($normalized[$pinCode])) {
                $duplicates[] = $normalized[$pinCode];
            }
        }

        return $this->ok('Duplicate check complete', [
            'duplicates' => $duplicates,
        ]);
    }

    /**
     * Denominations users can actually be sold, taken from epin_prices.
     */
    private function allowedDenominations(): array
    {
        $amounts = EpinPrice::query()->distinct()->pluck('amount')->map(fn ($a) => (int) $a)->all();

        return ! empty($amounts) ? $amounts : [100, 200, 500, 1000];
    }

    /**
     * Slips print PINs grouped with hyphens or spaces; those are decoration.
     * Store the bare value so duplicate detection and dialling both work.
     */
    private function normalizePinCode(?string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
    }

    /**
     * Toggle EPIN source mode between 'database' and 'nellobytes'.
     */
    public function toggleSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => 'required|string|in:database,nellobytes',
        ]);

        $config = ApiConfig::where('name', 'epinSourceMode')->first();

        if (! $config) {
            ApiConfig::create([
                'name' => 'epinSourceMode',
                'value' => $validated['mode'],
            ]);
        } else {
            $config->update(['value' => $validated['mode']]);
        }

        Log::info('EPIN source mode toggled', ['mode' => $validated['mode']]);

        return $this->ok('EPIN source mode updated', [
            'mode' => $validated['mode'],
        ]);
    }

    /**
     * Get current source mode.
     */
    public function getSourceMode(): JsonResponse
    {
        $config = ApiConfig::where('name', 'epinSourceMode')->first();
        $mode = $config ? $config->value : 'nellobytes';

        return $this->ok('Source mode retrieved', [
            'mode' => $mode,
        ]);
    }
}
