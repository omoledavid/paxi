<?php 

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\NbDataPlan as ResourcesNbDataPlan;
use App\Models\NbDataPlan;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\DataService;
use App\Traits\ApiResponses;
use Dflydev\DotAccessData\Data;
use Illuminate\Support\Facades\DB;

class DataController extends Controller
{
    use ApiResponses;

    protected DataService $dataService;

    public function __construct(DataService $dataService)
    {
        $this->dataService = $dataService;
    }

    function getDataplan()
    {
        try {
            $dataplans = $this->dataService->getDataplan();

            return $this->ok('Data plans retrieved successfully', ResourcesNbDataPlan::collection($dataplans));
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve data plans', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Failed to retrieve data plans', 500, $e->getMessage());
        }
    }
    function buyData()
    {
        $request = request();
        $user = auth()->user();

        $validated = $request->validate([
            'network_code' => 'required|string',
            'data_code' => 'required|string',
            'phone_number' => 'required|string',
        ]);
        $amount = getDataPlanPrice($validated['data_code']);
        $requestPayload = $validated;
        $transactionRef = generateTransactionRef();
        $transaction = NelloBytesTransaction::create([
            'user_id' => $user->sId,
            'service_type' => NelloBytesServiceType::DATA,
            'transaction_ref' => $transactionRef,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        DB::beginTransaction();
        try {
            $debit = debitWallet(
                user: $user,
                amount: getDataPlanPrice($request->input('data_code')),
                serviceName: 'NelloBytes Data Purchase',
                serviceDesc: 'Purchase of data plan via NelloBytes',
                transactionRef: $transactionRef,
                wrapInTransaction:false
            );
            $response = $this->dataService->purchaseData(
                $request->input('network_code'),
                $request->input('data_code'),
                $request->input('phone_number'),
                $transactionRef
            );
            $nellobytesRef = $response['reference'] ?? $response['ref'] ?? null;
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $response,
            ]);
            DB::commit();

            return $this->ok('Data purchase successful', $response);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Data purchase failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Data purchase failed', 500, $e->getMessage());
        }
    }
}