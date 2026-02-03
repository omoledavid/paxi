<?php

namespace App\Http\Controllers;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Enums\VtuAfricaServiceType;
use App\Http\Resources\NetworkResource;
use App\Models\ApiConfig;
use App\Models\DataPlan;
use App\Models\NelloBytesTransaction;
use App\Models\Network;
use App\Models\VtuAfricaTransaction;
use App\Models\VtpassTransaction;
use App\Services\NelloBytes\DataService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Services\Vtpass\DataService as VtpassDataService;
use App\Services\Vtpass\VtpassTransactionService;
use App\Services\VtuAfrica\DataService as VtuAfricaDataService;
use App\Traits\ApiResponses;
use DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DataController extends Controller
{
    use ApiResponses;

    protected DataService $dataService;
    protected NelloBytesTransactionService $nelloBytesTransactionService;
    protected VtpassTransactionService $vtpassTransactionService;

    public function __construct(
        DataService $dataService,
        NelloBytesTransactionService $nelloBytesTransactionService,
        VtpassTransactionService $vtpassTransactionService,
        protected VtpassDataService $vtpassDataService,
        protected VtuAfricaDataService $vtuAfricaDataService,
        protected \App\Services\VtuAfrica\VtuAfricaTransactionService $vtuAfricaTransactionService
    ) {
        $this->dataService = $dataService;
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
        $this->vtpassTransactionService = $vtpassTransactionService;
    }

    public function data(): JsonResponse
    {
        $data = Network::with('dataPlans')->get();

        // Priority: VTU Africa -> NelloBytes -> VTpass -> all
        if ($this->isVtuAfricaEnabled()) {
            $data = Network::with([
                'dataPlans' => function ($query) {
                    $query->where('service_type', 'vtuafrica');
                },
            ])->get();
        } elseif ($this->isNellobytesEnabled()) {
            $data = Network::with([
                'dataPlans' => function ($query) {
                    $query->where('service_type', 'nellobytes');
                },
            ])->get();
        } elseif ($this->isVtpassEnabled()) {
            $data = Network::with([
                'dataPlans' => function ($query) {
                    $query->where('service_type', 'vtpass');
                },
            ])->get();
        }

        return $this->ok('success', [
            'data_type' => ['SME', 'Gifting', 'Corporate'],
            'data' => NetworkResource::collection($data),
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
        // check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }
        $dataCode = DataPlan::find($validatedData['data_plan_id']);
        $networkID = '0' . $validatedData['network_id'];
        if ($dataCode) {
            $amount = $dataCode->userprice;
        } else {
            return $this->error('data plan not found');
        }
        // ref code
        $transRef = generateTransactionRef();

        // Priority: VTU Africa -> NelloBytes -> VTpass -> Legacy
        if ($this->isVtuAfricaEnabled()) {
            return $this->purchaseVtuAfricaData($validatedData, $user, $dataCode, $transRef);
        }

        if ($this->isNellobytesEnabled()) {
            DB::beginTransaction();
            $transaction = NelloBytesTransaction::create([
                'user_id' => $user->sId,
                'service_type' => NelloBytesServiceType::DATA,
                'transaction_ref' => $transRef,
                'amount' => $amount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);
            try {
                debitWallet(
                    user: $user,
                    amount: $amount,
                    serviceName: ' Data Purchase',
                    serviceDesc: 'Purchase of data plan',
                    transactionRef: $transRef,
                    wrapInTransaction: false
                );

                $response = $this->dataService->purchaseData(
                    $networkID,
                    $dataCode->planid,
                    $validatedData['phone_number'],
                    $transRef
                );

                // Use the new service to handle response and potential refunds
                $this->nelloBytesTransactionService->handleProviderResponse(
                    $response,
                    $transaction,
                    $user,
                    $amount
                );

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

        if ($this->isVtpassEnabled()) {
            $dataCode = DataPlan::find($validatedData['data_plan_id']);
            if (!$dataCode) {
                return $this->error('Data plan not found');
            }

            $txRef = generateTransactionRef();
            DB::beginTransaction();

            $transaction = VtpassTransaction::create([
                'user_id' => $user->sId,
                'service_type' => 'data',
                'transaction_ref' => $txRef,
                'amount' => $amount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);

            try {
                debitWallet(
                    user: $user,
                    amount: $amount,
                    serviceName: 'Data Purchase',
                    serviceDesc: "Data purchase for {$validatedData['phone_number']}",
                    transactionRef: $txRef,
                    wrapInTransaction: false
                );

                // Map network ID to ServiceID
                $providerMap = [
                    '1' => 'mtn-data',
                    '2' => 'glo-data',
                    '4' => 'airtel-data',
                    '3' => 'etisalat-data',
                    '5' => 'smile-direct',
                    '6' => 'spectranet',
                ];
                $networkCode = $validatedData['network_id'];
                $serviceID = $providerMap[$networkCode] ?? null;

                if (!$serviceID) {
                    throw new \Exception("Unsupported network ID: $networkCode");
                }

                // Extra payload for Smile and Spectranet
                $extraPayload = [];

                // Smile Logic
                if ($networkCode === '5') {
                    // Check if Smile Data is enabled
                    $smileDataStatus = getConfigValue(ApiConfig::all(), 'vtpassSmileDataStatus') ?? 'Off';
                    if ($smileDataStatus !== 'On') {
                        throw new \Exception("Smile Data service is currently disabled.");
                    }

                    $vtUsername = getConfigValue(ApiConfig::all(), 'vtUsername');
                    $vtPassword = getConfigValue(ApiConfig::all(), 'vtPassword');

                    if (empty($vtUsername) || empty($vtPassword)) {
                        throw new \Exception("Smile configuration missing username or password.");
                    }

                    $extraPayload = [
                        'username' => $vtUsername,
                        'password' => $vtPassword,
                    ];
                }

                // Spectranet Logic
                if ($networkCode === '6') {
                    // Check if Spectranet Data is enabled
                    $spectranetStatus = getConfigValue(ApiConfig::all(), 'vtpassSpectranetStatus') ?? 'Off';
                    if ($spectranetStatus !== 'On') {
                        throw new \Exception("Spectranet service is currently disabled.");
                    }

                    $vtUsername = getConfigValue(ApiConfig::all(), 'vtUsername');
                    $vtPassword = getConfigValue(ApiConfig::all(), 'vtPassword');

                    if (empty($vtUsername) || empty($vtPassword)) {
                        throw new \Exception("Spectranet configuration missing username or password.");
                    }

                    $extraPayload = [
                        'username' => $vtUsername,
                        'password' => $vtPassword,
                        'quantity' => 1, // Required parameter for Spectranet
                    ];
                }

                $response = $this->vtpassDataService->purchaseData(
                    requestId: $txRef,
                    serviceID: $serviceID,
                    phone: $validatedData['phone_number'],
                    variationCode: $dataCode->planid ?? 'unknown',
                    amount: $amount,
                    extra_payload: $extraPayload
                );

                // Use handleProviderResponse for automatic reversal on failure
                $this->vtpassTransactionService->handleProviderResponse(
                    $response,
                    $transaction,
                    $user,
                    $amount
                );

                DB::commit();

                return $this->ok('Data purchase successful', $response);

            } catch (\Exception $e) {
                DB::rollBack();
                return $this->error($e->getMessage());
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
                'ref' => $transRef,
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
    private function isVtpassEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtpassStatus') === 'On' &&
                getConfigValue($config, 'vtpassDataStatus') === 'On';
        }

        return $enabled;
    }

    private function isVtuAfricaEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtuAfricaStatus') === 'On' &&
                getConfigValue($config, 'vtuAfricaDataStatus') === 'On';
        }

        return $enabled;
    }

    /**
     * Purchase data via VTU Africa.
     */
    private function purchaseVtuAfricaData(array $validated, $user, DataPlan $dataCode, string $transRef): JsonResponse
    {
        $amount = $dataCode->userprice;

        // Map network ID and data type to VTU Africa service code
        $service = VtuAfricaDataService::mapServiceCode(
            $validated['network_id'],
            $validated['data_type'] ?? 'SME'
        );

        if (!$service) {
            return $this->error('Unsupported network for VTU Africa');
        }

        // Remove sensitive data from request payload
        $requestPayload = $validated;
        unset($requestPayload['pin']);

        DB::beginTransaction();

        $transaction = VtuAfricaTransaction::create([
            'user_id' => $user->sId,
            'service_type' => VtuAfricaServiceType::DATA,
            'transaction_ref' => $transRef,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        try {
            debitWallet(
                user: $user,
                amount: $amount,
                serviceName: 'Data Purchase',
                serviceDesc: "Data purchase for {$validated['phone_number']}",
                transactionRef: $transRef,
                wrapInTransaction: false
            );

            $result = $this->vtuAfricaDataService->purchaseData(
                service: $service,
                phoneNumber: $validated['phone_number'],
                dataPlan: $dataCode->planid,
                transactionRef: $transRef
            );

            // Use handleProviderResponse for automatic reversal on failure
            $this->vtuAfricaTransactionService->handleProviderResponse(
                $result,
                $transaction,
                $user,
                $amount
            );

            DB::commit();

            return $this->ok('Data purchase successful', [
                'reference' => $transRef,
                'vtuafrica_ref' => $result['reference'] ?? null,
                'data' => $result,
            ]);

        } catch (\App\Exceptions\VtuAfricaTransactionFailedException $e) {
            // Commit the transaction to save the "FAILED" status and the wallet refund
            DB::commit();
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error($e->getMessage());
        }
    }
}
