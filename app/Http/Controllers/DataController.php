<?php

namespace App\Http\Controllers;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Resources\DataResource;
use App\Http\Resources\NetworkResource;
use App\Models\ApiConfig;
use App\Models\DataPlan;
use App\Models\NelloBytesTransaction;
use App\Models\Network;
use App\Services\NelloBytes\DataService;
use App\Traits\ApiResponses;
use DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DataController extends Controller
{
    use ApiResponses;
    protected DataService $dataService;

    public function __construct(DataService $dataService)
    {
        $this->dataService = $dataService;
    }

    public function data(): JsonResponse
    {
        $data = Network::with('dataPlans')->get();
        if ($this->isNellobytesEnabled()) {
            $data = Network::with([
                'dataPlans' => function ($query) {
                    $query->where('service_type', 'nellobytes');
                }
            ])
                ->get();
        }
        return $this->ok('success', [
            'data_type' => ['SME', 'Gifting', 'Corporate'],
            'data' => NetworkResource::collection($data)
        ]);
    }

    public function purchaseData(Request $request): JsonResponse
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'network_id' => 'required',
            'data_type' => 'required',
            'data_plan_id' => 'required',
            'phone_number' => 'required',
            'pin' => 'required|digits:4|int',
        ]);
        //check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }
        $dataCode = DataPlan::find($validatedData['data_plan_id']);
        $networkID = '0'.$validatedData['network_id'];
        if($dataCode){
            $amount = $dataCode->price;
        }else{
            return $this->error('data plan not found');
        }
        //ref code
        $transRef = generateTransactionRef();
        $transaction = NelloBytesTransaction::create([
            'user_id' => $user->sId,
            'service_type' => NelloBytesServiceType::DATA,
            'transaction_ref' => $transRef,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $validatedData,
        ]);

        if ($this->isNellobytesEnabled()) {
            DB::beginTransaction();
            try {
                $debit = debitWallet(
                    user: $user,
                    amount: $amount,
                    serviceName: 'NelloBytes Data Purchase',
                    serviceDesc: 'Purchase of data plan via NelloBytes',
                    transactionRef: $transRef,
                    wrapInTransaction: false
                );
                $response = $this->dataService->purchaseData(
                    $networkID,
                    $dataCode->planid,
                    $validatedData['phone_number'],
                    $transRef
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

        $host = env('FRONTEND_URL') . '/api838190/data/';


        // Prepare request payload
        $payload = [
            'network' => $validatedData['network_id'],
            'phone' => $validatedData['phone_number'],
            'ported_number' => $request->boolean('ported_number', false) ? 'true' : 'false',
            'ref' => $transRef,
            'data_plan' => $validatedData['data_plan_id'],
        ];

        // Make API request
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Token' => "Token {$user->sApiKey}",
        ])->post($host, $payload);

        $result = $response->json();

        // Handle API response
        if ($response->successful() && $result['status'] === 'success') {
            return $this->ok('success', [
                'ref' => $transRef
            ]);
        } else {
            return $this->error($result['msg'] ?? 'Unknown error');
        }
    }
    private function isNellobytesEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'nellobytesStatus') === 'On' &&
                getConfigValue($config, 'nellobytesDataStatus') === 'On';
        }

        return $enabled;
    }
}
