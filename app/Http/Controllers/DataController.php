<?php

namespace App\Http\Controllers;

use App\Http\Resources\DataResource;
use App\Http\Resources\NetworkResource;
use App\Models\DataPlan;
use App\Models\Network;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DataController extends Controller
{
    use ApiResponses;

    public function data(): JsonResponse
    {
        $data = Network::all();
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
        //ref code
        $transRef = generateTransactionRef();

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
}
