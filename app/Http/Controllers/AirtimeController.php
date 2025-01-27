<?php

namespace App\Http\Controllers;

use App\Http\Resources\NetworkResource;
use App\Models\Network;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AirtimeController extends Controller
{
    use ApiResponses;
    public function index()
    {
        $data = Network::all();
        return $this->ok('success', [
            'type' => ['VTU', 'Share and sell'],
            'Network' => NetworkResource::collection($data)
        ]);
    }
    public function purchaseAirtime(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'network' => 'required',
            'type' => 'required',
            'phone_number' => 'required',
            'amount' => 'required|numeric|min:1',
            'pin' => 'required',

        ]);
        //check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }
        $host = env('FRONTEND_URL') . '/api838190/data/';
        //ref code
        $transRef = generateTransactionRef();
        // Prepare request payload
        $payload = [
            'network' => $validatedData['network'],
            'amount' => $validatedData['amount'],
            'phone' => $validatedData['phone_number'],
            'ported_number' => false,
            'ref' => $transRef,
            'airtime_type' => $validatedData['type'],
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
                'ref' => $transRef,
            ]);
        } else {
            return $this->error($result['msg'] ?? 'Unknown error');
        }
    }
}
