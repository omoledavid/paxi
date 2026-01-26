<?php

namespace App\Http\Controllers;

use App\Enums\NelloBytesServiceType;
use App\Enums\TransactionStatus;
use App\Enums\VtuAfricaServiceType;
use App\Http\Resources\NetworkResource;
use App\Models\Airtime;
use App\Models\ApiConfig;
use App\Models\NelloBytesTransaction;
use App\Models\Network;
use App\Models\VtuAfricaTransaction;
use App\Models\VtpassTransaction;
use App\Services\NelloBytes\AirtimeService;
use App\Services\NelloBytes\NelloBytesTransactionService;
use App\Services\Vtpass\AirtimeService as VtpassAirtimeService;
use App\Services\Vtpass\VtpassTransactionService;
use App\Services\VtuAfrica\AirtimeService as VtuAfricaAirtimeService;
use App\Traits\ApiResponses;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AirtimeController extends Controller
{
    use ApiResponses;

    protected NelloBytesTransactionService $nelloBytesTransactionService;
    protected VtpassTransactionService $vtpassTransactionService;

    public function __construct(
        protected AirtimeService $airtimeService,
        protected VtpassAirtimeService $vtpassAirtimeService,
        protected VtuAfricaAirtimeService $vtuAfricaAirtimeService,
        NelloBytesTransactionService $nelloBytesTransactionService,
        VtpassTransactionService $vtpassTransactionService
    ) {
        $this->nelloBytesTransactionService = $nelloBytesTransactionService;
        $this->vtpassTransactionService = $vtpassTransactionService;
    }

    /**
     * Display available networks and airtime types.
     */
    public function index()
    {
        $networks = Network::all();

        return $this->ok('Networks retrieved successfully', [
            'types' => ['VTU', 'Share and Sell'],
            'networks' => NetworkResource::collection($networks),
        ]);
    }

    /**
     * Purchase airtime via configured provider.
     */
    public function purchaseAirtime(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'network' => 'required|exists:networkid,networkid', // better: validate against actual network ID or code
            'type' => 'required|in:VTU,Share and Sell',
            'phone_number' => 'required', // or use a suitable phone validation rule
            'amount' => 'required|numeric|min:50', // typical minimum airtime amount
            'pin' => 'required|string|size:4', // assuming 4-digit PIN
        ]);

        // Check transaction PIN first (for non-Nellobytes flow)
        if (!$this->isNellobytesEnabled() && !$this->isVtuAfricaEnabled()) {
            if (!hash_equals((string) $user->sPin, (string) $validated['pin'])) {
                throw ValidationException::withMessages([
                    'pin' => 'The provided PIN is incorrect.',
                ]);
            }
        }

        $transactionRef = generateTransactionRef();

        // Priority: VTU Africa -> Nellobytes -> VTpass -> Legacy
        if ($this->isVtuAfricaEnabled()) {
            return $this->purchaseVtuAfricaAirtime($validated, $user, $transactionRef);
        }

        // Route to Nellobytes if enabled
        if ($this->isNellobytesEnabled()) {
            $networkID = '0' . $validated['network'];
            $airtimeDiscount = Airtime::where('aNetwork', $validated['network'])->where('aType', 'VTU')->first();

            // Calculate discount based on user type
            $discountRate = match ((int) $user->sType) {
                1 => $airtimeDiscount->aUserDiscount,
                2 => $airtimeDiscount->aAgentDiscount,
                3 => $airtimeDiscount->aVendorDiscount,
                default => 100
            };

            $transaction = NelloBytesTransaction::create([
                'user_id' => $user->sId,
                'service_type' => NelloBytesServiceType::AIRTIME,
                'transaction_ref' => $transactionRef,
                'amount' => $validated['amount'],
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validated,
            ]);

            // Calculate payable amount: (Amount / 100) * DiscountRate
            $payableAmount = ($validated['amount'] / 100) * $discountRate;
            $result = $this->airtimeService->purchaseAirtime(
                networkCode: $networkID,
                phoneNumber: $validated['phone_number'],
                amount: $validated['amount'],
                transactionRef: $transactionRef,
            );

            if (isset($result['Error'])) {
                return $this->error($result['Error']['Message'] ?? 'Airtime purchase failed');
            }

            // Debit wallet after successful API call
            debitWallet(
                user: $user,
                amount: $payableAmount,
                serviceName: 'Airtime Purchase',
                serviceDesc: "Purchased NGN{$validated['amount']} airtime for {$validated['phone_number']} at NGN{$payableAmount}",
                transactionRef: $transactionRef,
                wrapInTransaction: false,
            );
            $this->nelloBytesTransactionService->handleProviderResponse(
                $result,
                $transaction,
                $user,
                $payableAmount
            );

            return $this->ok('Airtime purchase request is being processed', [
                'reference' => $transactionRef,
            ]);
        }

        if ($this->isVtpassEnabled()) {
            // Map network ID to VTpass serviceID
            $providerMap = [
                '1' => 'mtn',
                '2' => 'glo',
                '4' => 'airtel',
                '3' => 'etisalat'
            ];

            $networkCode = $validated['network'];
            $serviceID = $providerMap[$networkCode] ?? null;

            if (!$serviceID) {
                return $this->error('Unsupported network for VTpass');
            }

            DB::beginTransaction();

            // Create Transaction Log
            $transaction = VtpassTransaction::create([
                'user_id' => $user->sId,
                'service_type' => 'airtime',
                'transaction_ref' => $transactionRef,
                'amount' => $validated['amount'],
                'status' => TransactionStatus::PENDING,
                'request_payload' => $validated,
            ]);

            try {
                // Debit wallet first
                debitWallet(
                    user: $user,
                    amount: $validated['amount'],
                    serviceName: 'Airtime Purchase (VTpass)',
                    serviceDesc: "Purchased NGN{$validated['amount']} airtime for {$validated['phone_number']}",
                    transactionRef: $transactionRef,
                    wrapInTransaction: false,
                );

                $result = $this->vtpassAirtimeService->purchaseAirtime(
                    requestId: $transactionRef,
                    serviceID: $serviceID,
                    phone: $validated['phone_number'],
                    amount: $validated['amount']
                );

                // Use handleProviderResponse for automatic reversal on failure
                $this->vtpassTransactionService->handleProviderResponse(
                    $result,
                    $transaction,
                    $user,
                    $validated['amount']
                );

                DB::commit();

                return $this->ok('Airtime purchased successfully', [
                    'reference' => $transactionRef,
                    'vtpass_response' => $result
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                return $this->error($e->getMessage());
            }
        }

        $host = env('FRONTEND_URL') . '/api838190/airtime/';

        $payload = [
            'network' => $validated['network'],
            'amount' => $validated['amount'],
            'phone' => $validated['phone_number'],
            'ported_number' => false,
            'ref' => $transactionRef,
            'airtime_type' => $validated['type'],
        ];

        // legacy code
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Token' => "Token {$user->sApiKey}",
        ])->post($host, $payload);

        $result = $response->json();

        if ($response->failed() || ($result['status'] ?? null) !== 'success') {
            return $this->error($result['msg'] ?? 'Airtime purchase failed. Please try again.');
        }

        // Optionally debit wallet here too if not handled by webhook/callback
        // debitWallet(
        //     user: $user,
        //     amount: $validated['amount'],
        //     serviceName: 'Airtime Purchase',
        //     serviceDesc: "Purchased NGN{$validated['amount']} airtime for {$validated['phone_number']}",
        //     transactionRef: $transactionRef,
        //     wrapInTransaction: false,
        // );

        return $this->ok('Airtime purchased successfully', [
            'reference' => $transactionRef,
        ]);
    }

    /**
     * Check if Nellobytes provider is enabled.
     */
    private function isNellobytesEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'nellobytesStatus') === 'On' &&
                getConfigValue($config, 'nellobytesAirtimeStatus') === 'On';
        }

        return $enabled;
    }
    private function isVtpassEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtpassStatus') === 'On' &&
                getConfigValue($config, 'vtpassAirtimeStatus') === 'On';
        }

        return $enabled;
    }

    private function isVtuAfricaEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtuAfricaStatus') === 'On' &&
                getConfigValue($config, 'vtuAfricaAirtimeStatus') === 'On';
        }

        return $enabled;
    }

    /**
     * Purchase airtime via VTU Africa.
     */
    private function purchaseVtuAfricaAirtime(array $validated, $user, string $transactionRef)
    {
        // Map network ID to VTU Africa network code
        $network = VtuAfricaAirtimeService::mapNetworkCode($validated['network']);

        if (!$network) {
            return $this->error('Unsupported network for VTU Africa');
        }

        // Calculate discount if applicable
        $airtimeDiscount = Airtime::where('aNetwork', $validated['network'])->where('aType', 'VTU')->first();
        $discountRate = match ((int) $user->sType) {
            1 => $airtimeDiscount?->aUserDiscount ?? 100,
            2 => $airtimeDiscount?->aAgentDiscount ?? 100,
            3 => $airtimeDiscount?->aVendorDiscount ?? 100,
            default => 100
        };
        $payableAmount = ($validated['amount'] / 100) * $discountRate;

        // Remove sensitive data from request payload
        $requestPayload = $validated;
        unset($requestPayload['pin']);

        // Create transaction record
        $transaction = VtuAfricaTransaction::create([
            'user_id' => $user->sId,
            'service_type' => VtuAfricaServiceType::AIRTIME,
            'transaction_ref' => $transactionRef,
            'amount' => $validated['amount'],
            'status' => TransactionStatus::PENDING,
            'request_payload' => $requestPayload,
        ]);

        try {
            $result = $this->vtuAfricaAirtimeService->purchaseAirtime(
                network: $network,
                phoneNumber: $validated['phone_number'],
                amount: $validated['amount'],
                transactionRef: $transactionRef
            );

            // Update transaction to success
            $transaction->update([
                'status' => TransactionStatus::SUCCESS,
                'provider_ref' => $result['reference'] ?? null,
                'response_payload' => $result['raw_response'] ?? $result,
            ]);

            // Debit wallet after successful API call
            debitWallet(
                user: $user,
                amount: $payableAmount,
                serviceName: 'Airtime Purchase (VTU Africa)',
                serviceDesc: "Purchased NGN{$validated['amount']} airtime for {$validated['phone_number']} at NGN{$payableAmount}",
                transactionRef: $transactionRef,
                wrapInTransaction: false,
            );

            return $this->ok('Airtime purchased successfully', [
                'reference' => $transactionRef,
                'vtuafrica_ref' => $result['reference'] ?? null,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'error_message' => $e->getMessage(),
                'response_payload' => ['error' => $e->getMessage()],
            ]);

            return $this->error($e->getMessage());
        }
    }
}
