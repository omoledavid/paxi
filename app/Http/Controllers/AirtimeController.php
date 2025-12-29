<?php

namespace App\Http\Controllers;

use App\Http\Resources\NetworkResource;
use App\Models\ApiConfig;
use App\Models\Network;
use App\Services\NelloBytes\AirtimeService;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AirtimeController extends Controller
{
    use ApiResponses;

    public function __construct(protected AirtimeService $airtimeService)
    {
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
            'network'       => 'required|exists:networks,id', // better: validate against actual network ID or code
            'type'          => 'required|in:VTU,Share and Sell',
            'phone_number'  => 'required|phone:NG', // or use a suitable phone validation rule
            'amount'        => 'required|numeric|min:50', // typical minimum airtime amount
            'pin'           => 'required|string|size:4', // assuming 4-digit PIN
        ]);

        // Check transaction PIN first (for non-Nellobytes flow)
        if (!$this->isNellobytesEnabled()) {
            if (!hash_equals($user->sPin, $validated['pin'])) {
                throw ValidationException::withMessages([
                    'pin' => 'The provided PIN is incorrect.',
                ]);
            }
        }

        $transactionRef = generateTransactionRef();

        // Route to Nellobytes if enabled
        if ($this->isNellobytesEnabled()) {
            $result = $this->airtimeService->purchaseAirtime(
                networkCode: $validated['network'],
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
                amount: $validated['amount'],
                serviceName: 'Airtime Purchase',
                serviceDesc: "Purchased ₦{$validated['amount']} airtime for {$validated['phone_number']}",
                transactionRef: $transactionRef,
                wrapInTransaction: false,
            );

            return $this->ok('Airtime purchase request is being processed', [
                'reference' => $transactionRef,
            ]);
        }

        // Fallback to legacy API (your current provider)
        $response = Http::withToken($user->sApiKey)
            ->contentType('application/json')
            ->post(rtrim(env('FRONTEND_URL'), '/') . '/api838190/airtime/', [
                'network'        => $validated['network'],
                'amount'         => $validated['amount'],
                'phone'          => $validated['phone_number'],
                'ported_number'  => false,
                'ref'            => $transactionRef,
                'airtime_type'   => $validated['type'],
            ]);

        $result = $response->json();

        if ($response->failed() || ($result['status'] ?? null) !== 'success') {
            return $this->error($result['msg'] ?? 'Airtime purchase failed. Please try again.');
        }

        // Optionally debit wallet here too if not handled by webhook/callback
        // debitWallet(
        //     user: $user,
        //     amount: $validated['amount'],
        //     serviceName: 'Airtime Purchase',
        //     serviceDesc: "Purchased ₦{$validated['amount']} airtime for {$validated['phone_number']}",
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
}