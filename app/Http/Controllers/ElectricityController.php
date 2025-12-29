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
use App\Traits\ApiResponses;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ElectricityController extends Controller
{
    use ApiResponses;
    protected ElectricityService $electricityService;

    public function __construct(ElectricityService $electricityService)
    {
        $this->electricityService = $electricityService;
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
    private function purchaseElectricityNellobytes($validatedData, $meterType, $transRef, $user)
    {
        // purchase data using nellobytes
        DB::beginTransaction();
        try {
            $transaction = NelloBytesTransaction::create([
                'user_id' => $user->sId,
                'service_type' => NelloBytesServiceType::ELECTRICITY,
                'transaction_ref' => $transRef,
                'amount' => $validatedData['amount'],
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);
            $debit = debitWallet(
                user: $user,
                amount: $validatedData['amount'],
                serviceName: 'NelloBytes Electricity Purchase',
                serviceDesc: 'Purchase of electricity plan via NelloBytes',
                transactionRef: $transRef,
                wrapInTransaction: false
            );
            $response = $this->electricityService->purchaseElectricity($validatedData['provider_id'], $meterType, $validatedData['meter_no'], $validatedData['amount'], $transRef);
            $nellobytesRef = $response['reference'] ?? $response['ref'] ?? null;
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $response,
            ]);
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
