<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExamProviderResource;
use App\Models\ExamProvider;
use App\Traits\ApiResponses;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class ExamCardController extends Controller
{
    use ApiResponses;

    public function index()
    {
        $examCard = ExamProvider::all();
        return $this->ok('success', ExamProviderResource::collection($examCard));
    }
    public function purchaseExamCardPin(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'provider' => 'required',
            'quantity' => 'required',
            'pin' => 'required',
        ]);

        //check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }
        $host = env('FRONTEND_URL') . '/api838190/exam/';
        //ref code
        $transRef = generateTransactionRef();

        $payload = [
            'provider' => $request->provider,
            'quantity' => $request->quantity,
            'ref' => $transRef,
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
}
