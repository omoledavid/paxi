<?php

namespace App\Http\Controllers;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Resources\CableTvResource;
use App\Models\ApiConfig;
use App\Models\CableTv;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\CableTvService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Traits\ApiResponses;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CableTvController extends Controller
{
    use ApiResponses;

    protected CableTvService $cableTvService;
    protected NelloBytesTransactionService $nelloBytesTransactionService;

    public function __construct(CableTvService $cableTvService, NelloBytesTransactionService $nelloBytesTransactionService)
    {
        $this->cableTvService = $cableTvService;
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
    }

    public function index()
    {
        $cableTv = CableTv::with('plans')->get();
        if ($this->isNellobytesEnabled()) {
            $cableTv = $cableTv->map(function ($cableTv) {
                $cableTv->plans = $cableTv->plans->filter(function ($plan) {
                    return !is_numeric($plan->planid);
                });

                return $cableTv;
            });
        }

        return $this->ok('success', [
            'subscription_type' => ['Change', 'Renew'],
            'cableTv' => CableTvResource::collection($cableTv),
        ]);
    }
    public function purchaseCableTv(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'provider_id' => 'required',
            'plan_id' => 'required',
            'price' => 'required|integer|min:1',
            'type' => 'required',
            'customer_no' => 'required',
            'iuc_no' => 'required',
            'pin' => 'required',
        ]);
        if ($this->isNellobytesEnabled()) {
            return $this->purchaseNellobytesCableTv($validatedData, $user);
        }
        $validatedIUC = $this->validateIUCNumber($validatedData['provider_id'], $validatedData['iuc_no'], $user->sApiKey);
        if ($validatedIUC['status'] == 'fail' || $validatedIUC['status'] == 'failed') {
            return $this->error([
                'error' => 'IUC number validation failed',
                'msg' => $validatedIUC['msg'],
                'rawdata' => $validatedIUC
            ], 400);
        }
        //check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }
        $host = env('FRONTEND_URL') . '/api838190/cabletv/';
        //ref code
        $transRef = generateTransactionRef();
        // Prepare API request payload
        $payload = [
            'provider' => $request->provider_id,
            'customer_no' => $request->customer_no,
            'type' => $request->type,
            'iucnumber' => $request->iuc_no,
            'ref' => $transRef,
            'plan' => $request->plan_id,
        ];

        // Send API request
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Token' => "Token {$user->sApiKey}",
        ])->post($host, $payload);

        $result = $response->json();

        // Handle API response
        if ($response->successful() && $result['status'] === 'success') {
            return $this->ok('success', ['ref' => $transRef]);
        } else {
            return $this->error($result['msg'] ?? 'Server error occurred.');
        }

    }
    private function validateIUCNumber(string $provider, string $iucNumber, $apiKey)
    {
        $siteUrl = env('FRONTEND_URL');
        ;
        $apiUrl = $siteUrl . "/api838190/cabletv/verify/";

        // Send request using Laravel's Http client
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Token' => "Token $apiKey",
        ])->post($apiUrl, [
                    'provider' => $provider,
                    'iucnumber' => $iucNumber,
                ]);

        // Decode response
        $result = $response->json();

        return $result;
    }
    public function verifyIUC(Request $request)
    {
        $user = auth()->user();
        $request->validate([
            'provider_id' => 'required',
            'iuc_no' => 'required',
        ]);
        $data = $this->validateIUCNumber($request->provider_id, $request->iuc_no, $user->sApiKey);
        return $this->ok('success', $data);
    }
    private function purchaseNellobytesCableTv($validatedData, $user)
    {
        $transRef = generateTransactionRef();
        DB::beginTransaction();
        try {

            $cableTv = CableTv::find($validatedData['provider_id']);
            $cableTvPlan = $cableTv->plans->where('cpId', $validatedData['plan_id'])->first();
            $transaction = NelloBytesTransaction::create([
                'user_id' => $user->sId,
                'service_type' => NelloBytesServiceType::CABLETV,
                'transaction_ref' => $transRef,
                'amount' => $cableTvPlan->userprice,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);
            $debit = debitWallet(
                user: $user,
                amount: $cableTvPlan->userprice,
                serviceName: 'CableTV Purchase',
                serviceDesc: 'Purchase of cabletv plan',
                transactionRef: $transRef,
                wrapInTransaction: false
            );
            $response = $this->cableTvService->purchaseCableTv(CableTV: strtolower($cableTv->provider), Package: $cableTvPlan->planid, smartCardNo: $validatedData['iuc_no'], PhoneNo: $validatedData['customer_no']);

            // Use the new service to handle response and potential refunds
                $this->nelloBytesTransactionService->handleProviderResponse(
                    $response,
                    $transaction,
                    $user,
                    $cableTvPlan->userprice
                );
            $nellobytesRef = $response['reference'] ?? $response['ref'] ?? null;
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $response,
            ]);
            DB::commit();

            return $this->ok('CableTV purchase successful', $response);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('CableTV purchase failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('CableTV purchase failed', 500, $e->getMessage());
        }
        
    }
    private function isNellobytesEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'nellobytesStatus') === 'On' &&
                getConfigValue($config, 'nellobytesCableStatus') === 'On';
        }

        return $enabled;
    }
}
