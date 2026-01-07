<?php

namespace App\Http\Controllers;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Http\Resources\ElectricityCompanyResource;
use App\Http\Resources\ElectricityResource;
use App\Models\ApiConfig;
use App\Models\EProvider;
use App\Models\NelloBytesTransaction;
use App\Services\NelloBytes\ElectricityService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Traits\ApiResponses;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ElectricityController extends Controller
{
    use ApiResponses;
    protected ElectricityService $electricityService;
    protected NelloBytesTransactionService $nelloBytesTransactionService;

    public function __construct(ElectricityService $electricityService, NelloBytesTransactionService $nelloBytesTransactionService)
    {
        $this->electricityService = $electricityService;
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
    }

    public function index()
    {
        if ($this->isNellobytesEnabled()) {
            $response = $this->electricityService->getElectricityProviders();

            if (!$response || !isset($response['ELECTRIC_COMPANY'])) {
                return $this->error('Failed to fetch electricity providers', 400);
            }

            // Flatten: extract the single object from each disco's array
            $providers = collect($response['ELECTRIC_COMPANY'])
                ->flatten(1) // turns [[obj], [obj], ...] → [obj, obj, ...]
                ->values(); // re-index numerically

            return $this->ok('success', [
                'provider' => ElectricityCompanyResource::collection($providers),
                'meter_type' => ['Prepaid', 'Postpaid']
            ]);
        }

        // Fallback to your local DB
        $electricity = EProvider::query()->get();
        return $this->ok('success', [
            'provider' => ElectricityResource::collection($electricity),
            'meter_type' => ['Prepaid', 'Postpaid']
        ]);
    }

    public function purchaseElectricity(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'provider_id' => 'required|string',
            'meter_type' => 'required|string',
            'meter_no' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'pin' => 'required',
        ]);
        //validate meter no
        $validateMeter = validateMeterNumber($validatedData['provider_id'], $validatedData['meter_no'], $validatedData['meter_type'], $user->sApiKey);

        if ($validateMeter === null || !is_array($validateMeter)) {
            return $this->error('Failed to validate meter number. Please try again.', 400);
        }

        if ($validateMeter['status'] == 'fail' || $validateMeter['status'] == 'failed') {
            return $this->error($validateMeter['msg'], 400);
        }
        //check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }

        //ref code
        $transRef = generateTransactionRef();

        //nelly
        if ($this->isNellobytesEnabled()) {
            $meterType = ($validatedData['meter_type'] == 'prepaid') ? 01 : 02;
            return $this->purchaseElectricityNellobytes($validatedData, $meterType, $transRef, $user);
        }

        $host = env('FRONTEND_URL') . '/api838190/electricity/';
        // Prepare API request payload
        $payload = [
            'provider' => $request->provider_id,
            'phone' => $request->phone_no,
            'metertype' => $request->meter_type,
            'meternumber' => $request->meter_no,
            'ref' => $transRef,
            'amount' => $request->amount,
        ];

        // Send API request
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

    public function verifyMeterNo(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'provider_id' => 'required|string',
            'meter_type' => 'required|string',
            'meter_no' => 'required|string',
        ]);
        if ($this->isNellobytesEnabled()) {
            $validateMeter = $this->electricityService->VeryMeterNumber($validatedData['provider_id'], $validatedData['meter_no'], $validatedData['meter_type']);

            if ($validateMeter === null || !is_array($validateMeter)) {
                return $this->error('Failed to validate meter number. Please try again.', 400);
            }

            if ($validateMeter['status'] == 'fail' || $validateMeter['status'] == 'failed') {
                return $this->error($validateMeter['msg'], 400);
            }
            return $this->ok('Verified meter no', [
                'customer_name' => $validateMeter['customer_name'] ?? $validateMeter['name'],
                'meter_number' => $validatedData['meter_no'],
            ]);
        }
        //validate meter no
        $validateMeter = validateMeterNumber($validatedData['provider_id'], $validatedData['meter_no'], $validatedData['meter_type'], $user->sApiKey);

        if ($validateMeter === null || !is_array($validateMeter)) {
            return $this->error('Failed to validate meter number. Please try again.', 400);
        }

        if ($validateMeter['status'] == 'fail' || $validateMeter['status'] == 'failed') {
            return $this->error($validateMeter['msg'], 400);
        }
        return $this->ok('Verified meter no', [
            'customer_name' => $validateMeter['Customer_Name'] ?? $validateMeter['name'],
            'meter_number' => $validatedData['meter_no'],
        ]);
    }

    public function purchaseHistory(Request $request)
    {
        $user = auth()->user();

        // Retrieve transactions
        $transactions = NelloBytesTransaction::query()
            ->where('user_id', $user->sId)
            ->where('service_type', NelloBytesServiceType::ELECTRICITY)
            ->latest()
            ->paginate(20);

        $transactions->getCollection()->transform(function ($transaction) {
            $responsePayload = $transaction->response_payload ?? [];
            $requestPayload = $transaction->request_payload ?? [];

            return [
                'orderid' => $transaction->transaction_ref,
                'statuscode' => $transaction->status === TransactionStatus::SUCCESS ? '100' : '0',
                'status' => strtoupper($transaction->status->value),
                'meterno' => $requestPayload['meter_no'] ?? null,
                'metertoken' => $responsePayload['metertoken'] ?? null,
                'amount' => $transaction->amount,
                'date' => $transaction->created_at,
            ];
        });

        return $this->ok('Electricity purchase history', $transactions);
    }
    private function purchaseElectricityNellobytes($validatedData, $meterType, $transRef, $user)
    {
        // purchase data using nellobytes
        DB::beginTransaction();
        try {
            $amount = $validatedData['amount'];
            $transaction = NelloBytesTransaction::create([
                'user_id' => $user->sId,
                'service_type' => NelloBytesServiceType::ELECTRICITY,
                'transaction_ref' => $transRef,
                'amount' => $amount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);
            $debit = debitWallet(
                user: $user,
                amount: $amount,
                serviceName: 'NelloBytes Electricity Purchase',
                serviceDesc: 'Purchase of electricity plan via NelloBytes',
                transactionRef: $transRef,
                wrapInTransaction: false
            );
            $response = $this->electricityService->purchaseElectricity($validatedData['provider_id'], $meterType, $validatedData['meter_no'], $validatedData['amount'], $transRef, $user->sPhone);
            $nellobytesRef = $response['reference'] ?? $response['ref'] ?? null;
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $response,
            ]);

            $this->nelloBytesTransactionService->handleProviderResponse(
                $response,
                $transaction,
                $user,
                $amount
            );

            // Check if token exists in response and send email
            if (isset($response['metertoken']) || ($validatedData['meter_type'] === 'prepaid' && isset($response['metertoken']))) {
                try {
                    \Illuminate\Support\Facades\Mail::to($user)->send(new \App\Mail\SendElectricityToken(
                        $response['metertoken'],
                        $amount,
                        $validatedData['meter_no'],
                        $transRef
                    ));
                } catch (\Exception $e) {
                    \Log::error('Failed to send electricity token email', ['error' => $e->getMessage()]);
                }
            }
            DB::commit();

            return $this->ok('Electricity purchase successful', $response);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Electricity purchase failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Electricity purchase failed', 500, $e->getMessage());
        }

    }
    private function isNellobytesEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'nellobytesStatus') === 'On' &&
                getConfigValue($config, 'nellobytesElectricityStatus') === 'On';
        }

        return $enabled;
    }
}
