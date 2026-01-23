<?php

namespace App\Http\Controllers;

use App\Http\Resources\NetworkResource;
use App\Models\Airtime;
use App\Models\ApiConfig;
use App\Models\Network;
use App\Services\NelloBytes\AirtimeService;
use App\Services\Vtpass\AirtimeService as VtpassAirtimeService;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AirtimeController extends Controller
{
    use ApiResponses;

    public function __construct(
        protected AirtimeService $airtimeService,
        protected VtpassAirtimeService $vtpassAirtimeService
    ) {
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
        if (!$this->isNellobytesEnabled()) {
            if (!hash_equals((string) $user->sPin, (string) $validated['pin'])) {
                throw ValidationException::withMessages([
                    'pin' => 'The provided PIN is incorrect.',
                ]);
            }
        }

        $transactionRef = generateTransactionRef();

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

            return $this->ok('Airtime purchase request is being processed', [
                'reference' => $transactionRef,
            ]);
        }

        if ($this->isVtpassEnabled()) {
            // Calculate discount if applicable or just process
            // Need to map network ID to VTpass serviceID (mtn, glo, airtel, etisalat)
            $providerMap = [
                '1' => 'mtn',
                '2' => 'glo',
                '4' => 'airtel',
                '3' => 'etisalat'
            ];

            // If network input is ID, map it. If it's already a string, use it (but validation expects exists:networkid context implies ID)
            // Assuming validated['network'] comes as ID from frontend based on existing code logic (0 + network)
            $networkCode = $validated['network'];
            $serviceID = $providerMap[$networkCode] ?? null;

            if (!$serviceID) {
                return $this->error('Unsupported network for VTpass');
            }

            // Create Transaction Log
            $transaction = \App\Models\VtpassTransaction::create([
                'user_id' => $user->sId,
                'service_type' => 'airtime',
                'transaction_ref' => $transactionRef,
                'amount' => $validated['amount'],
                'status' => \App\Enums\TransactionStatus::PENDING,
                'request_payload' => $validated,
            ]);

            $result = $this->vtpassAirtimeService->purchaseAirtime(
                requestId: $transactionRef,
                serviceID: $serviceID,
                phone: $validated['phone_number'],
                amount: $validated['amount']
            );

            // Update Transaction Log
            $responseCode = $result['code'] ?? '999';
            $status = ($responseCode === '000') ? \App\Enums\TransactionStatus::SUCCESS : \App\Enums\TransactionStatus::FAILED;

            $transaction->update([
                'status' => $status,
                'vtpass_ref' => $result['content']['transactions']['transactionId'] ?? null,
                'response_payload' => $result,
                'error_code' => $responseCode
            ]);

            // Process result...
            $responseCode = $result['code'] ?? '999';
            if ($responseCode === '000') {
                // Debit here or rely on service/hook? 
                // Existing code debits explicitly for Nellobytes
                debitWallet(
                    user: $user,
                    amount: $validated['amount'], // Apply discount logic if needed
                    serviceName: 'Airtime Purchase (VTpass)',
                    serviceDesc: "Purchased NGN{$validated['amount']} airtime for {$validated['phone_number']}",
                    transactionRef: $transactionRef,
                    wrapInTransaction: false,
                );
                return $this->ok('Airtime purchased successfully', [
                    'reference' => $transactionRef,
                    'vtpass_response' => $result
                ]);
            } else {
                return $this->error($result['response_description'] ?? 'Airtime purchase failed');
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
}
