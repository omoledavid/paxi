<?php

namespace App\Http\Controllers;

use App\Http\Resources\ElectricityResource;
use App\Models\EProvider;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ElectricityController extends Controller
{
    use ApiResponses;

    public function index()
    {
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
            'amount' => 'required|numeric',
            'pin' => 'required',
        ]);
        //validate meter no
        $validateMeter = validateMeterNumber($validatedData['provider_id'], $validatedData['meter_no'], $validatedData['meter_type'], $user->sApiKey);

        if ($validateMeter['status'] == 'fail' || $validateMeter['status'] == 'failed') {
            return $this->error($validateMeter['msg'], 400);
        }
        //check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }

        //ref code
        $transRef = generateTransactionRef();

        $host = env('FRONTEND_URL') . '/api838190/electricity/';
        // Prepare API request payload
        $payload = [
            'provider' => $request->provider_id,
            'phone' => $request->phone,
            'metertype' => $request->metertype,
            'meternumber' => $request->meternumber,
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
        //validate meter no
        $validateMeter = validateMeterNumber($validatedData['provider_id'], $validatedData['meter_no'], $validatedData['meter_type'], $user->sApiKey);
        if ($validateMeter['status'] == 'fail' || $validateMeter['status'] == 'failed') {
            return $this->error($validateMeter['msg'], 400);
        }
        return $this->ok('Verified meter no',[
            'customer_name' => $validateMeter['Customer_Name'] ?? $validateMeter['name'],
            'meter_number' => $validatedData['meter_no'],
        ]);
    }
}
