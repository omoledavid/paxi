<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
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
                'Insufficient NelloBytes wallet balance. Available: ' .
                ($walletCheck['balance'] ?? 0) . ', Required: ' . $totalAmount,
                402,
                [
                    'balance' => $walletCheck['balance'] ?? 0,
                    'required' => $totalAmount,
                ]
            );
        }

        $transactionRef = generateTransactionRef();
        $batchReference = 'BATCH-' . date('Ymd') . '-' . strtoupper(substr(md5($transactionRef), 0, 8));

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
            foreach ($txnPins as $pinData) {
                $epin = Epin::create([
                    'user_id' => null,
                    'transaction_id' => $transaction->id,
                    'network' => $validated['mobile_network'],
                    'amount' => $validated['value'],
                    'pin_code' => $pinData['pin'] ?? $pinData['CardPin'] ?? 'UNKNOWN',
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
                $e->getMessage() . ' Use the Sync button on the batch to recover PINs if NelloBytes already processed the order.',
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
        $totalPurchased = Epin::where('source', 'admin_bulk')->count();
        $available = Epin::where('source', 'admin_bulk')
            ->whereNull('user_id')
            ->where('status', 'unused')
            ->count();
        $disbursed = Epin::where('source', 'admin_bulk')
            ->whereNotNull('user_id')
            ->count();
        $totalSpent = (float) Epin::where('source', 'admin_bulk')
            ->sum('purchase_cost');

        $byNetwork = Epin::where('source', 'admin_bulk')
            ->selectRaw('network, COUNT(*) as count')
            ->groupBy('network')
            ->get()
            ->keyBy('network')
            ->toArray();

        $byNetworkAvailable = Epin::where('source', 'admin_bulk')
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

        $query = Epin::query()->where('source', 'admin_bulk');

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

        $batches = Epin::where('source', 'admin_bulk')
            ->selectRaw("
                batch_reference,
                network,
                amount,
                COUNT(*) as pin_count,
                SUM(purchase_cost) as total_cost,
                MIN(created_at) as purchased_at,
                SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as disbursed_count,
                SUM(CASE WHEN user_id IS NULL AND status = 'unused' THEN 1 ELSE 0 END) as available_count
            ")
            ->groupBy('batch_reference', 'network', 'amount')
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

        $transaction = NelloBytesTransaction::where('transaction_ref', function ($q) use ($batchRef) {
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

            $savedPinCodes = $existingPins->pluck('pin_code')->toArray();
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

            return $this->error('Failed to sync batch: ' . $e->getMessage(), 500);
        }
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
