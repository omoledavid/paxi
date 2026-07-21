<?php

namespace App\Http\Controllers;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Enums\VtuAfricaServiceType;
use App\Http\Resources\ElectricityCompanyResource;
use App\Http\Resources\ElectricityResource;
use App\Mail\SendElectricityToken;
use App\Models\ApiConfig;
use App\Models\EProvider;
use App\Models\ElectricityDiscount;
use App\Models\NelloBytesTransaction;
use App\Models\PaystackTransaction;
use App\Models\VtuAfricaTransaction;
use App\Models\VtpassTransaction;
use App\Services\NelloBytes\ElectricityService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Services\Paystack\ElectricityService as PaystackElectricityService;
use App\Services\Paystack\PaystackTransactionService;
use App\Services\Vtpass\VtpassTransactionService;
use App\Services\VtuAfrica\ElectricityService as VtuAfricaElectricityService;
use App\Services\ReferralBonusService;
use App\Traits\ApiResponses;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class ElectricityController extends Controller
{
    use ApiResponses;

    protected ElectricityService $electricityService;
    protected PaystackElectricityService $paystackElectricityService;
    protected NelloBytesTransactionService $nelloBytesTransactionService;
    protected PaystackTransactionService $paystackTransactionService;
    protected VtpassTransactionService $vtpassTransactionService;

    public function __construct(
        ElectricityService $electricityService,
        NelloBytesTransactionService $nelloBytesTransactionService,
        PaystackElectricityService $paystackElectricityService,
        PaystackTransactionService $paystackTransactionService,
        VtpassTransactionService $vtpassTransactionService,
        protected \App\Services\Vtpass\ElectricityService $vtpassElectricityService,
        protected VtuAfricaElectricityService $vtuAfricaElectricityService
    ) {
        $this->electricityService = $electricityService;
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
        $this->paystackElectricityService = $paystackElectricityService;
        $this->paystackTransactionService = $paystackTransactionService;
        $this->vtpassTransactionService = $vtpassTransactionService;
    }

    public function index()
    {
        // Priority: VTU Africa -> NelloBytes -> Paystack -> fallback
        if ($this->isVtuAfricaEnabled()) {
            // VTU Africa uses static provider list from config
            // Map to format expected by ElectricityCompanyResource
            // Look up local provider ID for discount mapping
            $providers = collect(config('vtuafrica.electricity_providers', []))->map(function ($provider) {
                $configName = $provider['name'] ?? '';
                $configCode = $provider['code'] ?? '';
                
                // Extract name before parentheses (e.g., "Port Harcourt Electric (PHEDC)" -> "Port Harcourt Electric")
                $extractedName = preg_replace('/\s*\([^)]*\)/', '', $configName);
                
                // Extract abbreviation from parentheses (e.g., "Port Harcourt Electric (PHEDC)" -> "PHEDC")
                preg_match('/\(([^)]+)\)/', $configName, $matches);
                $extractedAbbreviation = $matches[1] ?? '';
                
                // Try multiple matching strategies
                $localProvider = EProvider::where('provider', 'LIKE', '%' . $extractedName . '%')
                    ->orWhere('abbreviation', $extractedAbbreviation)
                    ->orWhere('abbreviation', strtoupper($configCode))
                    ->first();
                
                return [
                    'ID' => $localProvider ? $localProvider->eId : $configCode,
                    'NAME' => $configName,
                    'provider_id' => $localProvider ? $localProvider->eId : null,
                ];
            });

            return $this->ok('success', [
                'provider' => ElectricityCompanyResource::collection($providers),
                'meter_type' => ['Prepaid', 'Postpaid'],
            ]);
        } elseif ($this->isNellobytesEnabled()) {
            $response = $this->electricityService->getElectricityProviders();

            if (!$response || !isset($response['ELECTRIC_COMPANY'])) {
                return $this->error('Failed to fetch electricity providers', 400);
            }

            // Flatten: extract the single object from each disco's array
            $providers = collect($response['ELECTRIC_COMPANY'])
                ->flatten(1) // turns [[obj], [obj], ...] → [obj, obj, ...]
                ->map(function ($provider) {
                    $providerName = $provider['NAME'] ?? $provider['name'] ?? '';
                    // Look up local provider by provider name OR abbreviation for discount mapping
                    $localProvider = EProvider::where('provider', 'LIKE', '%' . $providerName . '%')
                        ->orWhere('abbreviation', 'LIKE', '%' . $providerName . '%')
                        ->orWhere(function ($query) use ($providerName) {
                            // Also try matching if provider name contains abbreviation (e.g., "Port Harcourt Electric (PHEDC)")
                            $query->where('abbreviation', 'LIKE', '%' . str_replace(['(', ')', ' '], '', $providerName) . '%');
                        })
                        ->first();
                    
                    return [
                        'ID' => $localProvider ? $localProvider->eId : ($provider['ID'] ?? $provider['id']),
                        'NAME' => $providerName,
                        'provider_id' => $localProvider ? $localProvider->eId : null,
                    ];
                })
                ->values(); // re-index numerically

            return $this->ok('success', [
                'provider' => ElectricityCompanyResource::collection($providers),
                'meter_type' => ['Prepaid', 'Postpaid'],
            ]);
        } elseif ($this->isPaystackEnabled()) {
            $response = $this->paystackElectricityService->getProviders();
            // Map Paystack response
            if (isset($response['data'])) {
                $providers = collect($response['data'])->map(function ($provider) {
                    $providerName = $provider['name'] ?? '';
                    // Look up local provider by provider name OR abbreviation for discount mapping
                    $localProvider = EProvider::where('provider', 'LIKE', '%' . $providerName . '%')
                        ->orWhere('abbreviation', 'LIKE', '%' . $providerName . '%')
                        ->orWhere(function ($query) use ($providerName) {
                            // Also try matching if provider name contains abbreviation
                            $query->where('abbreviation', 'LIKE', '%' . str_replace(['(', ')', ' '], '', $providerName) . '%');
                        })
                        ->first();
                    
                    return (object) [
                        'beId' => $localProvider ? $localProvider->eId : $provider['id'],
                        'name' => $provider['name'],
                        'code' => $provider['id'] ?? $provider['code'], // Paystack code
                        'provider_id' => $localProvider ? $localProvider->eId : null,
                    ];
                });

                return $this->ok('success', [
                    'provider' => ElectricityCompanyResource::collection($providers),
                    'meter_type' => ['Prepaid', 'Postpaid'],
                ]);
            }
        }

        // Fallback to local DB logic (default)

        // Fallback to your local DB
        $electricity = EProvider::query()->get();

        return $this->ok('success', [
            'provider' => ElectricityResource::collection($electricity),
            'meter_type' => ['Prepaid', 'Postpaid'],
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
        // check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }

        // ref code
        $transRef = generateTransactionRef();

        // Calculate discounted amount
        $originalAmount = (float) $validatedData['amount'];
        $providerId = (int) $validatedData['provider_id'];
        $discountInfo = ElectricityDiscount::calculatePayableAmount($originalAmount, $providerId, (int) $user->sType);
        
        // Store discount info in validated data for use in purchase methods
        $validatedData['original_amount'] = $originalAmount;
        $validatedData['payable_amount'] = $discountInfo['payable_amount'];
        $validatedData['discount_applied'] = $discountInfo['discount_applied'];
        $validatedData['discount_amount'] = $discountInfo['discount_amount'];
        $validatedData['discount_percentage'] = $discountInfo['discount_percentage'];

        // Priority: VTU Africa -> NelloBytes -> Paystack -> VTpass -> Legacy
        if ($this->isVtuAfricaEnabled()) {
            return $this->purchaseElectricityVtuAfrica($validatedData, $transRef, $user);
        } elseif ($this->isNellobytesEnabled()) {
            $meterType = ($validatedData['meter_type'] == 'prepaid') ? 01 : 02;

            return $this->purchaseElectricityNellobytes($validatedData, $meterType, $transRef, $user);
        } elseif ($this->isPaystackEnabled()) {
            return $this->purchaseElectricityPaystack($validatedData, $transRef, $user);
        } elseif ($this->isVtpassEnabled()) {
            return $this->purchaseElectricityVtpass($validatedData, $transRef, $user);
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
            ReferralBonusService::credit($user, $validatedData['amount'], ReferralBonusService::METER, $transRef);

            return $this->ok('success', [
                'ref' => $transRef,
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

        // Priority: VTU Africa -> NelloBytes -> Paystack -> VTpass -> Legacy
        if ($this->isVtuAfricaEnabled()) {
            try {
                $provider = EProvider::where('eId', $validatedData['provider_id'])->first();
                $service = VtuAfricaElectricityService::mapServiceCode(
                    $provider->abbreviation ?? $validatedData['provider_id']
                );

                $response = $this->vtuAfricaElectricityService->verifyMeter(
                    service: $service,
                    meterNo: $validatedData['meter_no'],
                    meterType: $validatedData['meter_type']
                );

                return $this->ok('Verified meter no', [
                    'customer_name' => $response['customer_name'] ?? 'Unknown',
                    'meter_number' => $validatedData['meter_no'],
                    'address' => $response['address'] ?? null,
                ]);
            } catch (\Exception $e) {
                return $this->error('Meter validation failed: ' . $e->getMessage(), 400);
            }
        } elseif ($this->isNellobytesEnabled()) {
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
        } elseif ($this->isPaystackEnabled()) {
            $validateMeter = $this->paystackElectricityService->verifyMeter($validatedData['provider_id'], $validatedData['meter_no'], $validatedData['meter_type']);
            if (!($validateMeter['status'] ?? false)) {
                return $this->error($validateMeter['message'] ?? 'Validation failed', 400);
            }

            return $this->ok('Verified meter no', [
                'customer_name' => $validateMeter['data']['customer_name'] ?? $validateMeter['data']['name'] ?? 'Customer',
                'meter_number' => $validatedData['meter_no'],
            ]);
        } elseif ($this->isVtpassEnabled()) {
            try {
                $providerMap = $this->getVtpassProviderMap();
                $provider = EProvider::where('eId', $validatedData['provider_id'])->first();
                $serviceID = $providerMap[$provider->abbreviation] ?? $validatedData['provider_id'];

                $response = $this->vtpassElectricityService->verifyMeter(
                    $serviceID,
                    $validatedData['meter_no'],
                    $validatedData['meter_type']
                );

                $name = $response['content']['Customer_Name'] ?? 'Unknown';

                if (!isset($response['content']['Customer_Name'])) {
                    return $this->error('Invalid Meter Number');
                }

                return $this->ok('Verified meter no', [
                    'customer_name' => $name,
                    'meter_number' => $validatedData['meter_no'],
                ]);
            } catch (\Exception $e) {
                return $this->error('Validation failed: ' . $e->getMessage());
            }
        }
        // validate meter no
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
        $limit = 20;

        // 1. NelloBytes Query
        $nelloBytes = DB::table('nellobytes_transactions')
            ->select([
                'transaction_ref',
                'amount',
                'status',
                'created_at',
                'request_payload',
                'response_payload',
                DB::raw("'nellobytes' as provider"),
            ])
            ->where('user_id', $user->sId)
            ->where('service_type', NelloBytesServiceType::ELECTRICITY->value);

        // 2. VTpass Query
        $vtpass = DB::table('vtpass_transactions')
            ->select([
                'transaction_ref',
                'amount',
                'status',
                'created_at',
                'request_payload',
                'response_payload',
                DB::raw("'vtpass' as provider"),
            ])
            ->where('user_id', $user->sId)
            ->where('service_type', 'electricity-bill');

        // 3. Paystack Query
        $paystack = DB::table('paystack_transactions')
            ->select([
                'transaction_ref',
                'amount',
                'status',
                'created_at',
                'request_payload',
                'response_payload',
                DB::raw("'paystack' as provider"),
            ])
            ->where('user_id', $user->sId)
            ->where('service_type', \App\Enums\PaystackServiceType::ELECTRICITY->value);

        // 4. VtuAfrica Query
        $vtuAfrica = DB::table('vtuafrica_transactions')
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
            ->where('service_type', VtuAfricaServiceType::ELECTRICITY->value);

        // Union All
        $query = $nelloBytes
            ->unionAll($vtpass)
            ->unionAll($paystack)
            ->unionAll($vtuAfrica)
            ->orderBy('created_at', 'desc');

        // Paginate manually or using a helper if available, but for union standard paginate() might be tricky on older Laravel versions
        // In recent Laravel versions, query builder union pagination works nicely.
        $transactions = $query->paginate($limit);

        $transactions->getCollection()->transform(function ($transaction) {
            $responsePayload = json_decode($transaction->response_payload, true) ?? [];
            $requestPayload = json_decode($transaction->request_payload, true) ?? [];

            // Extract Token based on Provider/Structure
            $token = null;

            // Common patterns
            $token = $responsePayload['description']['Token'] // Nested Token (VtuAfrica common)
                ?? $responsePayload['purchased_code'] // Vtpass / VtuAfrica sometimes
                ?? $responsePayload['mainToken'] // Vtpass
                ?? $responsePayload['metertoken'] // NelloBytes
                ?? $responsePayload['data']['token'] // Paystack sometimes
                ?? null;

            // Normalize Status
            // DB returns string for status usually, check if it matches enum values
            $status = $transaction->status; // "pending", "success", "failed"

            return [
                'orderid' => $transaction->transaction_ref,
                'provider' => $transaction->provider,
                'statuscode' => ($status === TransactionStatus::SUCCESS->value || $status === 'success') ? '100' : '0',
                'status' => strtoupper($status),
                'meterno' => $requestPayload['meter_no'] ?? $requestPayload['meter_number'] ?? null,
                'metertoken' => $token,
                'amount' => $transaction->amount,
                'date' => $transaction->created_at,
            ];
        });

        return $this->ok('Electricity purchase history', $transactions);
    }

    private function purchaseElectricityNellobytes($validatedData, $meterType, $transRef, $user)
    {
        // Check NelloBytes wallet balance before proceeding
        $payableAmount = $validatedData['payable_amount'] ?? ($validatedData['original_amount'] ?? $validatedData['amount']);
        $walletCheck = checkServiceWallet('nellobytes', $payableAmount);
        if ($walletCheck['status'] !== 'success' || !$walletCheck['has_sufficient']) {
            return $this->error('Service unavailable at the moment. Please try again later.');
        }

        // purchase data using nellobytes
        DB::beginTransaction();
        try {
            $originalAmount = $validatedData['original_amount'] ?? $validatedData['amount'];
            $payableAmount = $validatedData['payable_amount'] ?? $originalAmount;
            
            $transaction = NelloBytesTransaction::create([
                'user_id' => $user->sId,
                'service_type' => NelloBytesServiceType::ELECTRICITY,
                'transaction_ref' => $transRef,
                'amount' => $payableAmount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);
            $debit = debitWallet(
                user: $user,
                amount: $payableAmount,
                serviceName: 'Electricity Purchase',
                serviceDesc: 'Purchase of electricity plan',
                transactionRef: $transRef,
                wrapInTransaction: false
            );

            $response = $this->electricityService->purchaseElectricity(
                $validatedData['provider_id'],
                $meterType,
                $validatedData['meter_no'],
                $validatedData['amount'],
                $user->sPhone,
                $transRef
            );

            $this->nelloBytesTransactionService->handleProviderResponse(
                $response,
                $transaction,
                $user,
                $payableAmount
            );

            // Check if token exists in response and send email
            if (isset($response['metertoken']) || ($validatedData['meter_type'] === 'prepaid' && isset($response['metertoken']))) {
                try {
                    \Illuminate\Support\Facades\Mail::to($user->sEmail)->send(new \App\Mail\SendElectricityToken(
                        $response['metertoken'],
                        $originalAmount,
                        $validatedData['meter_no'],
                        $transRef
                    ));
                } catch (\Exception $e) {
                    \Log::error('Failed to send electricity token email', ['error' => $e->getMessage()]);
                }
            }
            DB::commit();

            ReferralBonusService::credit($user, $payableAmount, ReferralBonusService::METER, $transRef);

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

    private function purchaseElectricityPaystack($validatedData, $transRef, $user)
    {
        // Check Paystack doesn't have wallet check, but we keep pattern consistent
        // Paystack uses direct payment so wallet check is not applicable
        DB::beginTransaction();
        try {
            $originalAmount = $validatedData['original_amount'] ?? $validatedData['amount'];
            $payableAmount = $validatedData['payable_amount'] ?? $originalAmount;
            
            $transaction = PaystackTransaction::create([
                'user_id' => $user->sId,
                'service_type' => \App\Enums\PaystackServiceType::ELECTRICITY,
                'transaction_ref' => $transRef,
                'amount' => $payableAmount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);

            debitWallet(
                user: $user,
                amount: $payableAmount,
                serviceName: 'Electricity Purchase',
                serviceDesc: 'Purchase of electricity plan',
                transactionRef: $transRef,
                wrapInTransaction: false
            );

            $response = $this->paystackElectricityService->purchaseElectricity(
                provider: $validatedData['provider_id'],
                meterNumber: $validatedData['meter_no'],
                meterType: $validatedData['meter_type'],
                amount: $originalAmount,
                phoneNo: $user->sPhone,
                email: $user->email
            );

            $this->paystackTransactionService->handleProviderResponse(
                $response,
                $transaction,
                $user,
                $payableAmount
            );

            // Send standard email logic (same as NelloBytes usually)
            // But Paystack might return token differently.
            if (isset($response['data']['token'])) {
                try {
                    \Illuminate\Support\Facades\Mail::to($user->sEmail)->send(new \App\Mail\SendElectricityToken(
                        $response['data']['token'],
                        $originalAmount,
                        $validatedData['meter_no'],
                        $transRef
                    ));
                } catch (\Exception $e) {
                    \Log::error('Failed to send electricity token email', ['error' => $e->getMessage()]);
                }
            }

            DB::commit();

            ReferralBonusService::credit($user, $payableAmount, ReferralBonusService::METER, $transRef);

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

    private function purchaseElectricityVtpass($validatedData, $transRef, $user)
    {
        // Check VTpass wallet balance before proceeding
        $payableAmount = $validatedData['payable_amount'] ?? ($validatedData['original_amount'] ?? $validatedData['amount']);
        $walletCheck = checkServiceWallet('vtpass', $payableAmount);
        if ($walletCheck['status'] !== 'success' || !$walletCheck['has_sufficient']) {
            return $this->error('Service unavailable at the moment. Please try again later.');
        }

        DB::beginTransaction();
        try {
            $originalAmount = $validatedData['original_amount'] ?? $validatedData['amount'];
            $payableAmount = $validatedData['payable_amount'] ?? $originalAmount;
            
            $transaction = VtpassTransaction::create([
                'user_id' => $user->sId,
                'service_type' => 'electricity-bill',
                'transaction_ref' => $transRef,
                'amount' => $payableAmount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);

            debitWallet(
                user: $user,
                amount: $payableAmount,
                serviceName: 'Electricity Purchase',
                serviceDesc: 'Purchase of electricity token',
                transactionRef: $transRef,
                wrapInTransaction: false
            );

            $providerMap = $this->getVtpassProviderMap();
            $provider = EProvider::where('eId', $validatedData['provider_id'])->first();
            $serviceID = $providerMap[$provider->abbreviation] ?? $validatedData['provider_id'];

            $response = $this->vtpassElectricityService->purchaseElectricity(
                requestId: $transRef,
                serviceID: $serviceID,
                meterNumber: $validatedData['meter_no'],
                type: $validatedData['meter_type'],
                amount: $originalAmount,
                phone: $user->sPhone
            );

            // Use handleProviderResponse for automatic reversal on failure
            $this->vtpassTransactionService->handleProviderResponse(
                $response,
                $transaction,
                $user,
                $payableAmount
            );

            // Email Logic for successful transaction
            $token = $response['purchased_code'] ?? $response['mainToken'] ?? $response['token'] ?? null;
            if ($token) {
                try {
                    \Illuminate\Support\Facades\Mail::to($user->sEmail)->send(new \App\Mail\SendElectricityToken(
                        $token,
                        $originalAmount,
                        $validatedData['meter_no'],
                        $transRef
                    ));
                } catch (\Exception $e) {
                    \Log::error('Failed to send electricity token email', ['error' => $e->getMessage()]);
                }
            }

            DB::commit();

            ReferralBonusService::credit($user, $payableAmount, ReferralBonusService::METER, $transRef);

            return $this->ok('Electricity purchase successful', $response);

        } catch (\App\Exceptions\VtpassTransactionFailedException $e) {
            // Commit the transaction to save the "FAILED" status and the wallet refund
            DB::commit();
            return $this->error($e->getMessage());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage());
        }
    }

    private function getVtpassProviderMap()
    {
        // Maps DB Abbreviation -> VTpass Service ID
        return [
            'IE' => 'ikeja-electric',
            'EKEDC' => 'eko-electric',
            'AEDC' => 'abuja-electric',
            'KEDCO' => 'kano-electric',
            'PHEDC' => 'portharcourt-electric',
            'JED' => 'jos-electric',
            'KEDC' => 'kaduna-electric',
            'ENUGU' => 'enugu-electric',
            'IBEDC' => 'ibadan-electric',
            'BENIN' => 'benin-electric',
            'ABA' => 'aba-electric',
            'YOLA' => 'yola-electric',
            'ABEDC' => 'aba-electric',
        ];
    }

    private function isPaystackEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'paystackStatus') === 'On' &&
                getConfigValue($config, 'vtpassElectricityStatus') === 'On';
        }

        return $enabled;
    }

    private function isVtpassEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtpassStatus') === 'On' &&
                getConfigValue($config, 'vtpassElectricityStatus') === 'On';
        }

        return $enabled;
    }

    private function isVtuAfricaEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtuAfricaStatus') === 'On' &&
                getConfigValue($config, 'vtuAfricaElectricityStatus') === 'On';
        }

        return $enabled;
    }

    private function purchaseElectricityVtuAfrica($validatedData, $transRef, $user)
    {
        // Check VTU Africa wallet balance before proceeding
        $payableAmount = $validatedData['payable_amount'] ?? ($validatedData['original_amount'] ?? $validatedData['amount']);
        $walletCheck = checkServiceWallet('vtuafrica', $payableAmount);
        if ($walletCheck['status'] !== 'success' || !$walletCheck['has_sufficient']) {
            return $this->error('Service unavailable at the moment. Please try again later.');
        }

        DB::beginTransaction();

        // Remove sensitive data from request payload
        $requestPayload = $validatedData;
        unset($requestPayload['pin']);

        $originalAmount = $validatedData['original_amount'] ?? $validatedData['amount'];
        $payableAmount = $validatedData['payable_amount'] ?? $originalAmount;

        $transaction = VtuAfricaTransaction::create([
            'user_id' => $user->sId,
            'service_type' => VtuAfricaServiceType::ELECTRICITY,
            'transaction_ref' => $transRef,
            'amount' => $payableAmount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        try {
            debitWallet(
                user: $user,
                amount: $payableAmount,
                serviceName: 'Electricity Purchase',
                serviceDesc: 'Purchase of electricity token',
                transactionRef: $transRef,
                wrapInTransaction: false
            );

            $provider = EProvider::where('eId', $validatedData['provider_id'])->first();
            $service = VtuAfricaElectricityService::mapServiceCode(
                $provider->abbreviation ?? $validatedData['provider_id']
            );

            $response = $this->vtuAfricaElectricityService->purchaseElectricity(
                service: $service,
                meterNo: $validatedData['meter_no'],
                meterType: $validatedData['meter_type'],
                amount: $originalAmount,
                transactionRef: $transRef
            );

            // Update transaction to success
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'provider_ref' => $response['reference'] ?? null,
                'response_payload' => $response['raw_response'] ?? $response,
            ]);

            // Send token email if available
            $token = $response['token'] ?? null;
            if ($token) {
                try {
                    Mail::to($user->sEmail)->send(new SendElectricityToken(
                        $token,
                        $originalAmount,
                        $validatedData['meter_no'],
                        $transRef
                    ));
                } catch (\Exception $e) {
                    \Log::error('Failed to send electricity token email', ['error' => $e->getMessage()]);
                }
            }

            DB::commit();

            ReferralBonusService::credit($user, $originalAmount, ReferralBonusService::METER, $transRef);

            return $this->ok('Electricity purchase successful', [
                'reference' => $transRef,
                'vtuafrica_ref' => $response['reference'] ?? null,
                'token' => $token,
                'data' => $response,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'error_message' => $e->getMessage(),
                'response_payload' => ['error' => $e->getMessage()],
            ]);

            \Log::error('Electricity purchase failed (VTU Africa)', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Electricity purchase failed', 500, $e->getMessage());
        }
    }
}
