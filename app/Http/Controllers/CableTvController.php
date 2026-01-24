<?php

namespace App\Http\Controllers;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Enums\VtuAfricaServiceType;
use App\Http\Resources\CableTvResource;
use App\Models\ApiConfig;
use App\Models\CableTv;
use App\Models\NelloBytesTransaction;
use App\Models\PaystackTransaction;
use App\Models\VtuAfricaTransaction;
use App\Services\NelloBytes\CableTvService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Services\Paystack\CableTvService as PaystackCableTvService;
use App\Services\Paystack\PaystackTransactionService;
use App\Services\Vtpass\TvSubscriptionService as VtpassTvService;
use App\Services\VtuAfrica\CableTvService as VtuAfricaCableTvService;
use App\Traits\ApiResponses;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CableTvController extends Controller
{
    use ApiResponses;

    protected CableTvService $cableTvService;

    protected PaystackCableTvService $paystackCableTvService;

    protected NelloBytesTransactionService $nelloBytesTransactionService;

    protected PaystackTransactionService $paystackTransactionService;

    public function __construct(
        CableTvService $cableTvService,
        NelloBytesTransactionService $nelloBytesTransactionService,
        PaystackCableTvService $paystackCableTvService,
        PaystackTransactionService $paystackTransactionService,
        protected VtpassTvService $vtpassTvService,
        protected VtuAfricaCableTvService $vtuAfricaCableTvService
    ) {
        $this->cableTvService = $cableTvService;
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
        $this->paystackCableTvService = $paystackCableTvService;
        $this->paystackTransactionService = $paystackTransactionService;
    }

    public function index()
    {
        $cableTv = CableTv::with('plans')->get();

        // Priority: VTU Africa -> NelloBytes -> Paystack -> VTpass
        if ($this->isVtuAfricaEnabled()) {
            // VTU Africa uses static plans from config
            $cableTvPlans = config('vtuafrica.cabletv_plans', []);

            $cableTv = collect([
                (object) [
                    'cId' => '1',
                    'provider' => 'GOTV',
                    'providerStatus' => 'Active',
                    'logo' => null,
                    'plans' => collect($cableTvPlans['gotv'] ?? [])->map(function ($plan) {
                        return (object) [
                            'cpId' => $plan['code'],
                            'name' => $plan['name'],
                            'userprice' => $plan['price'],
                            'day' => '30',
                            'planid' => $plan['code'],
                        ];
                    }),
                ],
                (object) [
                    'cId' => '2',
                    'provider' => 'DSTV',
                    'providerStatus' => 'Active',
                    'logo' => null,
                    'plans' => collect($cableTvPlans['dstv'] ?? [])->map(function ($plan) {
                        return (object) [
                            'cpId' => $plan['code'],
                            'name' => $plan['name'],
                            'userprice' => $plan['price'],
                            'day' => '30',
                            'planid' => $plan['code'],
                        ];
                    }),
                ],
                (object) [
                    'cId' => '3',
                    'provider' => 'STARTIMES',
                    'providerStatus' => 'Active',
                    'logo' => null,
                    'plans' => collect($cableTvPlans['startimes'] ?? [])->map(function ($plan) {
                        return (object) [
                            'cpId' => $plan['code'],
                            'name' => $plan['name'],
                            'userprice' => $plan['price'],
                            'day' => '30',
                            'planid' => $plan['code'],
                        ];
                    }),
                ],
            ]);
        } elseif ($this->isNellobytesEnabled()) {
            $response = $this->cableTvService->getPlans();

            if (isset($response['TV_ID'])) {
                $cableTv = collect($response['TV_ID'])->map(function ($items, $providerName) {
                    // Items is an array of provider details, usually just one entry
                    $info = $items[0] ?? [];
                    $products = $info['PRODUCT'] ?? [];

                    return (object) [
                        'cId' => $info['ID'] ?? strtolower($providerName),
                        'provider' => $providerName,
                        'providerStatus' => 'Active',
                        'plans' => collect($products)->map(function ($plan) {
                            return (object) [
                                'cpId' => $plan['PACKAGE_ID'],
                                'name' => $plan['PACKAGE_NAME'],
                                'userprice' => $plan['PACKAGE_AMOUNT'],
                                'day' => '30', // Default value or derived if available
                                'planid' => $plan['PACKAGE_ID'], // ensure compatibility if used elsewhere
                            ];
                        }),
                    ];
                })->values();
            }

        } elseif ($this->isPaystackEnabled()) {
            $response = $this->paystackCableTvService->getPlans();
            // Map Paystack plans to expected structure
            if (isset($response['data'])) {
                $cableTv = collect($response['data'])->map(function ($provider) {
                    return (object) [
                        'cId' => $provider['id'] ?? strtolower($provider['name']), // Check paystack response
                        'provider' => $provider['name'],
                        'providerStatus' => 'Active',
                        'plans' => collect($provider['opts'] ?? [])->map(function ($plan) {
                            // Assuming standard format or arbitrary mapping
                            return (object) [
                                'cpId' => $plan['code'], // Paystack plan code
                                'name' => $plan['name'],
                                'userprice' => $plan['amount'] / 100, // Paystack is kobo
                                'day' => '30',
                                'planid' => $plan['code'],
                            ];
                        }),
                    ];
                })->values();
            }
        } elseif ($this->isVtpassEnabled()) {
            // VTpass Plan Fetching
            // Iterate over local providers and fetch variations for each
            // This might be slow if many providers, but standard for these integrations
            $cableTv = $cableTv->map(function ($provider) {
                try {
                    $map = $this->getVtpassCableTvProviderMap();
                    // Check by provider name (e.g. DSTV) or ID (e.g. 2)
                    $serviceID = $map[$provider->provider] ?? $map[$provider->cId] ?? null;

                    if (!$serviceID) {
                        return $provider;
                    }

                    $response = $this->vtpassTvService->getVariations($serviceID);

                    if (isset($response['content']['varations'])) {
                        $plans = collect($response['content']['varations'])->map(function ($plan) {
                            return (object) [
                                'cpId' => $plan['variation_code'],
                                'name' => $plan['name'],
                                'userprice' => $plan['variation_amount'],
                                'day' => '30', // Default
                                'planid' => $plan['variation_code'],
                            ];
                        });

                        // Return modified provider object structure
                        return (object) [
                            'cId' => $provider->cId,
                            'provider' => $provider->provider,
                            'providerStatus' => $provider->providerStatus, // Ensure status is passed
                            'plans' => $plans,
                            'logo' => $provider->logo ?? null
                        ];
                    }
                    return $provider;
                } catch (\Exception $e) {
                    return $provider;
                }
            });
        }

        return $this->ok('success', [
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
            'price' => 'required|integer|min:1',
            'type' => 'required',
            'customer_no' => 'nullable',
            'iuc_no' => 'required',
            'pin' => 'required',
        ]);
        if ($this->isVtuAfricaEnabled()) {
            return $this->purchaseVtuAfricaCableTv($validatedData, $user);
        } elseif ($this->isNellobytesEnabled()) {
            return $this->purchaseNellobytesCableTv($validatedData, $user);
        } elseif ($this->isPaystackEnabled()) {
            return $this->purchasePaystackCableTv($validatedData, $user);
        } elseif ($this->isVtpassEnabled()) {
            return $this->purchaseVtpassCableTv($validatedData, $user);
        }
        $validatedIUC = $this->validateIUCNumber($validatedData['provider_id'], $validatedData['iuc_no'], $user->sApiKey);
        if ($validatedIUC['status'] == 'fail' || $validatedIUC['status'] == 'failed') {
            return $this->error([
                'error' => 'IUC number validation failed',
                'msg' => $validatedIUC['msg'],
                'rawdata' => $validatedIUC,
            ], 400);
        }
        // check pin
        if ($user->sPin != $validatedData['pin']) {
            return $this->error('incorrect pin');
        }
        $host = env('FRONTEND_URL') . '/api838190/cabletv/';
        // ref code
        $transRef = generateTransactionRef();
        // Prepare API request payload
        $payload = [
            'provider' => $validatedData['provider_id'],
            'customer_no' => $validatedData['customer_no'] ?? $user->sPhone,
            'type' => $validatedData['type'],
            'iucnumber' => $validatedData['iuc_no'],
            'ref' => $transRef,
            'plan' => $validatedData['plan_id'],
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
        $siteUrl = env('FRONTEND_URL');

        $apiUrl = $siteUrl . '/api838190/cabletv/verify/';

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
            'plan_id' => 'nullable', // VTU Africa requires variation for verify
        ]);

        // Priority: VTU Africa -> NelloBytes -> Paystack -> VTpass -> Legacy
        if ($this->isVtuAfricaEnabled()) {
            try {
                $service = VtuAfricaCableTvService::mapServiceCode($request->provider_id);

                // VTU Africa requires variation (plan_id) for verification
                // Use provider-appropriate default if not provided
                $variation = $request->plan_id;
                if (!$variation) {
                    $defaultVariations = [
                        'gotv' => 'gotv_max',
                        'dstv' => 'dstv_padi',
                        'startimes' => 'startimes_nova',
                        'showmax' => 'full_3',
                    ];
                    $variation = $defaultVariations[$service] ?? 'gotv_max';
                }

                $response = $this->vtuAfricaCableTvService->verifySmartcard(
                    service: $service,
                    smartNo: $request->iuc_no,
                    variation: $variation
                );

                $customerName = $response['customer_name'] ?? 'Unknown';

                return $this->ok('success', [
                    'status' => 'success',
                    'Status' => 'successful',
                    'msg' => $customerName,
                    'name' => $customerName,
                    'Customer_Name' => $customerName,
                    'current_bouquet' => $response['current_bouquet'] ?? null,
                    'due_date' => $response['due_date'] ?? null,
                ]);
            } catch (\Exception $e) {
                return $this->error('Invalid IUC number or Service Unavailable');
            }
        } elseif ($this->isNellobytesEnabled()) {
            $response = $this->cableTvService->verifyIUC($request->provider_id, $request->iuc_no);
            if ($response['status'] == 'INVALID_CABLETV') {
                return $this->error('Invalid IUC number');
            }

            $formattedResponse = [
                'status' => 'success',
                'Status' => 'successful',
                'msg' => $response['customer_name'] ?? '',
                'name' => $response['customer_name'] ?? '',
                'Customer_Name' => $response['customer_name'] ?? '',
            ];

            return $this->ok('success', $formattedResponse);
        } elseif ($this->isPaystackEnabled()) {
            $response = $this->paystackCableTvService->verifyIUC($request->provider_id, $request->iuc_no);
            // handle Paystack verify
            if (!($response['status'] ?? false)) {
                return $this->error('Invalid IUC number');
            }

            $customerName = $response['data']['customer_name'] ?? $response['data']['name'] ?? 'Customer';

            $formattedResponse = [
                'status' => 'success',
                'Status' => 'successful',
                'msg' => $customerName,
                'name' => $customerName,
                'Customer_Name' => $customerName,
            ];

            return $this->ok('success', $formattedResponse);
            return $this->ok('success', $formattedResponse);
        } elseif ($this->isVtpassEnabled()) {
            try {
                $map = $this->getVtpassCableTvProviderMap();
                // Map input provider_id to VTpass serviceID
                // provider_id could be ID (1, 2) or Name (GOTV)
                $serviceID = $map[$request->provider_id] ?? strtolower($request->provider_id);

                // If it's not in the map, and not a standard one, it might fail, but let's try.
                // Best effort: if mapping exists use it, else use as is (lowercased)

                $response = $this->vtpassTvService->verifySmartcard($serviceID, $request->iuc_no);
                // Map VTpass response to local format
                $name = $response['content']['Customer_Name'] ?? 'Unknown';
                return $this->ok('success', [
                    'status' => 'success',
                    'Customer_Name' => $name,
                    'msg' => $name
                ]);
            } catch (\Exception $e) {
                return $this->error('Invalid IUC number or Service Unavailable');
            }
        }
        $data = $this->validateIUCNumber($request->provider_id, $request->iuc_no, $user->sApiKey);

        return $this->ok('success', $data);
    }

    private function purchaseNellobytesCableTv($validatedData, $user)
    {
        $transRef = generateTransactionRef();
        DB::beginTransaction();
        try {

            $cableTv = $validatedData['provider_id'];
            $cableTvPlan = $validatedData['plan_id'];
            $amount = $validatedData['price'];
            $transaction = NelloBytesTransaction::create([
                'user_id' => $user->sId,
                'service_type' => NelloBytesServiceType::CABLETV,
                'transaction_ref' => $transRef,
                'amount' => $amount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);
            $debit = debitWallet(
                user: $user,
                amount: $amount,
                serviceName: 'CableTV Purchase',
                serviceDesc: 'Purchase of cabletv plan',
                transactionRef: $transRef,
                wrapInTransaction: false
            );
            $response = $this->cableTvService->purchaseCableTv(CableTV: strtolower($cableTv), Package: $cableTvPlan, smartCardNo: $validatedData['iuc_no'], PhoneNo: $validatedData['customer_no'] ?? $user->sPhone);

            // Use the new service to handle response and potential refunds
            $this->nelloBytesTransactionService->handleProviderResponse(
                $response,
                $transaction,
                $user,
                $amount
            );
            $nellobytesRef = $response['reference'] ?? $response['ref'] ?? null;
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'nellobytes_ref' => $nellobytesRef,
                'response_payload' => $response,
            ]);
            DB::commit();

            return $this->ok('CableTV purchase successful', $response);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('CableTV purchase failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('CableTV purchase failed', 500, $e->getMessage());
        }

    }

    private function isNellobytesEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'nellobytesStatus') === 'On' &&
                getConfigValue($config, 'nellobytesCableStatus') === 'On';
        }

        return $enabled;
    }

    private function purchasePaystackCableTv($validatedData, $user)
    {
        $transRef = generateTransactionRef();
        DB::beginTransaction();
        try {

            $cableTv = $validatedData['provider_id'];
            $cableTvPlan = $validatedData['plan_id'];
            $amount = $validatedData['price'];

            $transaction = PaystackTransaction::create([
                'user_id' => $user->sId,
                'service_type' => \App\Enums\PaystackServiceType::CABLETV,
                'transaction_ref' => $transRef,
                'amount' => $amount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);

            debitWallet(
                user: $user,
                amount: $amount,
                serviceName: 'CableTV Purchase (Paystack)',
                serviceDesc: 'Purchase of cabletv plan',
                transactionRef: $transRef,
                wrapInTransaction: false
            );

            $response = $this->paystackCableTvService->purchaseCableTv(
                cableTv: strtolower($cableTv),
                packageCode: $cableTvPlan,
                smartCardNo: $validatedData['iuc_no'],
                phoneNo: $validatedData['customer_no'] ?? $user->sPhone,
                email: $user->email,
                amount: $amount
            );

            // Use the new service to handle response and potential refunds
            $this->paystackTransactionService->handleProviderResponse(
                $response,
                $transaction,
                $user,
                $amount
            );

            DB::commit();

            return $this->ok('CableTV purchase successful', $response);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('CableTV purchase failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('CableTV purchase failed', 500, $e->getMessage());
        }
    }

    private function purchaseVtpassCableTv($validatedData, $user)
    {
        $transRef = generateTransactionRef();
        $amount = $validatedData['price'];

        DB::beginTransaction();
        try {
            $transaction = \App\Models\VtpassTransaction::create([
                'user_id' => $user->sId,
                'service_type' => 'tv-subscription',
                'transaction_ref' => $transRef,
                'amount' => $amount,
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validatedData,
            ]);

            debitWallet(
                user: $user,
                amount: $amount,
                serviceName: 'CableTV Purchase (VTpass)',
                serviceDesc: 'Purchase of cabletv plan',
                transactionRef: $transRef,
                wrapInTransaction: false
            );

            $map = $this->getVtpassCableTvProviderMap();
            $serviceID = $map[$validatedData['provider_id']] ?? strtolower($validatedData['provider_id']);

            $response = $this->vtpassTvService->purchaseSubscription(
                requestId: $transRef,
                serviceID: $serviceID,
                smartcardNumber: $validatedData['iuc_no'],
                variationCode: $validatedData['plan_id'],
                amount: $amount,
                phone: $validatedData['customer_no'] ?? $user->sPhone
            );

            $responseCode = $response['code'] ?? '999';
            $status = ($responseCode === '000') ? TransactionStatus::SUCCESS : TransactionStatus::FAILED;

            $transaction->update([
                'status' => $status,
                'vtpass_ref' => $response['content']['transactions']['transactionId'] ?? null,
                'response_payload' => $response
            ]);

            DB::commit();

            if ($status === TransactionStatus::SUCCESS) {
                return $this->ok('CableTV purchase successful', $response);
            } else {
                return $this->error($response['response_description'] ?? 'Purchase failed');
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Purchase failed', 500, $e->getMessage());
        }
    }

    private function isVtpassEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtpassStatus') === 'On' &&
                getConfigValue($config, 'vtpassCableStatus') === 'On';
        }

        return $enabled;
    }

    private function isPaystackEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'paystackStatus') === 'On' &&
                getConfigValue($config, 'paystackCableStatus') === 'On';
        }

        return $enabled;
    }

    private function getVtpassCableTvProviderMap()
    {
        return [
            // DB ID -> VTpass Service ID
            '1' => 'gotv',
            '2' => 'dstv',
            '3' => 'startimes',
            '4' => 'showmax',
            // DB Name -> VTpass Service ID
            'GOTV' => 'gotv',
            'DSTV' => 'dstv',
            'STARTIMES' => 'startimes',
            'SHOWMAX' => 'showmax',
        ];
    }

    private function isVtuAfricaEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtuAfricaStatus') === 'On' &&
                getConfigValue($config, 'vtuAfricaCableStatus') === 'On';
        }

        return $enabled;
    }

    private function purchaseVtuAfricaCableTv($validatedData, $user)
    {
        $transRef = generateTransactionRef();
        $amount = $validatedData['price'];

        DB::beginTransaction();

        // Remove sensitive data from request payload
        $requestPayload = $validatedData;
        unset($requestPayload['pin']);

        $transaction = VtuAfricaTransaction::create([
            'user_id' => $user->sId,
            'service_type' => VtuAfricaServiceType::CABLETV,
            'transaction_ref' => $transRef,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        try {
            debitWallet(
                user: $user,
                amount: $amount,
                serviceName: 'CableTV Purchase (VTU Africa)',
                serviceDesc: 'Purchase of cabletv plan',
                transactionRef: $transRef,
                wrapInTransaction: false
            );

            $service = VtuAfricaCableTvService::mapServiceCode($validatedData['provider_id']);

            $response = $this->vtuAfricaCableTvService->purchaseSubscription(
                service: $service,
                smartNo: $validatedData['iuc_no'],
                variation: $validatedData['plan_id'],
                transactionRef: $transRef
            );

            // Update transaction to success
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'provider_ref' => $response['reference'] ?? null,
                'response_payload' => $response['raw_response'] ?? $response,
            ]);

            DB::commit();

            return $this->ok('CableTV purchase successful', [
                'reference' => $transRef,
                'vtuafrica_ref' => $response['reference'] ?? null,
                'data' => $response,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'error_message' => $e->getMessage(),
                'response_payload' => ['error' => $e->getMessage()],
            ]);

            // Refund the user
            creditWallet(
                user: $user,
                amount: $amount,
                serviceName: 'Wallet Refund',
                serviceDesc: 'Refund for failed VTU Africa cable TV transaction: ' . $transRef,
                transactionRef: null,
                wrapInTransaction: false
            );

            \Log::error('CableTV purchase failed (VTU Africa)', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('CableTV purchase failed', 500, $e->getMessage());
        }
    }
}
