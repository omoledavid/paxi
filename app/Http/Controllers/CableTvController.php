<?php

namespace App\Http\Controllers;

use App\Http\Resources\CableTvResource;
use App\Models\CableTv;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CableTvController extends Controller
{
    use ApiResponses;

    public function index()
    {
        $cableTv = CableTv::with('plans')->get();
        return $this->ok('success',[
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
            'price' => 'required',
            'type' => 'required',
            'customer_no' => 'required',
            'iuc_no' => 'required',
            'pin' => 'required',
        ]);
        $validatedIUC = $this->validateIUCNumber($validatedData['provider_id'], $validatedData['iuc_no'],$user->sApiKey);
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
        $siteUrl = env('FRONTEND_URL');;
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
        $data = $this->validateIUCNumber($request->provider_id, $request->iuc_no,$user->sApiKey);
        return $this->ok('success', $data);
    }
}
