<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Enums\VtuAfricaServiceType;
use App\Http\Resources\ExamProviderResource;
use App\Models\ApiConfig;
use App\Models\ExamProvider;
use App\Models\VtuAfricaTransaction;
use App\Services\VtuAfrica\ExamPinService;
use App\Services\VtuAfrica\VtuAfricaTransactionService;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ExamCardController extends Controller
{
    use ApiResponses;

    public function __construct(
        protected ExamPinService $examPinService,
        protected VtuAfricaTransactionService $vtuAfricaTransactionService
    ) {
    }

    public function index()
    {
        $examCard = ExamProvider::all();

        return $this->ok('success', ExamProviderResource::collection($examCard));
    }

    public function purchaseExamCardPin(Request $request)
    {
        $user = auth()->user();

        // 1. Initial Validation (Common)
        // Note: Legacy used 'provider_id', VTU Africa uses 'service' & 'product_code'.
        // We'll relax validation here and let specific handlers ensure what they need,
        // OR we can check the toggle first. However, to keep it clean, we'll validate common stuff.

        if ($this->isVtuAfricaEnabled()) {
            return $this->purchaseVtuAfricaExamPin($request, $user);
        }
        return;
        // --- LEGACY FLOW ---
        $validatedData = $request->validate([
            'provider_id' => 'required',
            'quantity' => 'required',
            'pin' => 'required',
        ]);

        // check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }

        $host = env('FRONTEND_URL') . '/api838190/exam/';
        // ref code
        $transRef = generateTransactionRef();
        $payload = [
            'provider' => $request->provider_id,
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

    private function isVtuAfricaEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtuAfricaStatus') === 'On' &&
                getConfigValue($config, 'vtuAfricaExamStatus') === 'On';
        }

        return $enabled;
    }

    private function purchaseVtuAfricaExamPin(Request $request, $user)
    {
        $validated = $request->validate([
            'provider_id' => 'required|string', // waec, neco, nabteb, jamb
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'pin' => 'required|string',
            // Optional params for JAMB
            'profilecode' => 'nullable|required_if:service,jamb|string',
            'sender' => 'nullable|required_if:service,jamb|email',
            'phone' => 'nullable|required_if:service,jamb|string',
        ]);

        $provider = ExamProvider::where('examid', $validated['provider_id'])->first();
        $service = strtolower($provider->provider);
        $productCode = 1;

        if ($user->sPin != $validated['pin']) {
            return $this->error('incorrect pin');
        }

        $transactionRef = generateTransactionRef();
        $amount = $validated['price'];
        $payableAmount = $amount; // Apply discount logic if needed

        $requestPayload = $validated;
        unset($requestPayload['pin']);

        DB::beginTransaction();

        $transaction = VtuAfricaTransaction::create([
            'user_id' => $user->sId,
            'service_type' => VtuAfricaServiceType::EXAM,
            'transaction_ref' => $transactionRef,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        try {
            // Debit wallet
            debitWallet(
                user: $user,
                amount: $payableAmount,
                serviceName: 'Exam PIN Purchase',
                serviceDesc: "Purchased {$service} PIN (Qty: {$validated['quantity']})",
                transactionRef: $transactionRef,
                wrapInTransaction: false,
            );

            // Prepare optional parameters
            $optionalParams = [];
            if ($service === 'jamb') {
                if (isset($validated['profilecode']))
                    $optionalParams['profilecode'] = $validated['profilecode'];
                if (isset($validated['sender']))
                    $optionalParams['sender'] = $validated['sender'];
                if (isset($validated['phone']))
                    $optionalParams['phone'] = $validated['phone'];
            }

            $result = $this->examPinService->purchaseExamPin(
                service: $service,
                productCode: $productCode,
                quantity: $validated['quantity'],
                transactionRef: $transactionRef,
                optionalParams: $optionalParams
            );

            $this->vtuAfricaTransactionService->handleProviderResponse(
                $result,
                $transaction,
                $user,
                $payableAmount
            );

            DB::commit();

            return $this->ok('Exam PIN purchased successfully', [
                'reference' => $transactionRef,
                'vtuafrica_ref' => $result['reference'] ?? null,
                'pins' => $result['pins'] ?? null,
                'data' => $result,
            ]);

        } catch (\App\Exceptions\VtuAfricaTransactionFailedException $e) {
            DB::commit();
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage());
        }
    }
    /**
     * Get exam PIN purchase history.
     */
    public function purchaseHistory(Request $request)
    {
        $user = auth()->user();
        $limit = 20;

        $query = DB::table('vtuafrica_transactions')
            ->select([
                'transaction_ref',
                'amount',
                'status',
                'created_at',
                'request_payload',
                'response_payload',
                DB::raw("'vtuafrica' as provider"),
            ])
            ->where('user_id', $user->sId)
            ->where('service_type', VtuAfricaServiceType::EXAM->value)
            ->orderBy('created_at', 'desc');

        $transactions = $query->paginate($limit);

        $transactions->getCollection()->transform(function ($transaction) {
            $responsePayload = json_decode($transaction->response_payload, true) ?? [];
            $requestPayload = json_decode($transaction->request_payload, true) ?? [];
            $description = $responsePayload['description'] ?? [];

            // Extract PINs
            // VTU Africa usually returns 'pins' in description for exam products
            $pins = $responsePayload['pins'] ?? null;
            $productName = $description['ProductName'] ?? $requestPayload['service'] ?? 'Exam PIN';

            $status = $transaction->status;

            return [
                'orderid' => $transaction->transaction_ref,
                'provider' => 'Paxi',
                'product_name' => $productName,
                'statuscode' => ($status === TransactionStatus::SUCCESS->value || $status === 'success') ? '100' : '0',
                'status' => strtoupper($status),
                'pins' => $pins,
                'amount' => $transaction->amount,
                'date' => $transaction->created_at,
            ];
        });

        return $this->ok('Exam PIN purchase history', $transactions);
    }
}
