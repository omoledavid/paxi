<?php

namespace App\Http\Controllers\Api\V1\Vtpass;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vtpass\InternationalAirtimePurchaseRequest;
use App\Models\ApiConfig;
use App\Models\VtpassTransaction;
use App\Services\ReferralBonusService;
use App\Services\Vtpass\InternationalAirtimeService;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InternationalAirtimeController extends Controller
{
    use ApiResponses;

    public function __construct(
        protected InternationalAirtimeService $intlAirtimeService
    ) {
    }

    public function getCountries()
    {
        if (! $this->isVtpassEnabled()) {
            return $this->error('International Airtime Service Currently Unavailable');
        }

        try {
            $response = $this->intlAirtimeService->getCountries();
            $countries = $response['content']['countries'] ?? [];

            return $this->ok('Countries retrieved', $countries);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve countries', 500, $e->getMessage());
        }
    }

    public function getProductTypes(Request $request)
    {
        if (! $this->isVtpassEnabled()) {
            return $this->error('International Airtime Service Currently Unavailable');
        }

        $validated = $request->validate([
            'country_code' => 'required|string',
        ]);

        try {
            $response = $this->intlAirtimeService->getProductTypes($validated['country_code']);
            $productTypes = $response['content'] ?? [];

            return $this->ok('Product types retrieved', $productTypes);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve product types', 500, $e->getMessage());
        }
    }

    public function getOperators(Request $request)
    {
        if (! $this->isVtpassEnabled()) {
            return $this->error('International Airtime Service Currently Unavailable');
        }

        $validated = $request->validate([
            'country_code' => 'required|string',
            'product_type_id' => 'required|integer',
        ]);

        try {
            $response = $this->intlAirtimeService->getOperators(
                $validated['country_code'],
                $validated['product_type_id']
            );
            $operators = $response['content'] ?? [];

            return $this->ok('Operators retrieved', $operators);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve operators', 500, $e->getMessage());
        }
    }

    public function getVariations(Request $request)
    {
        if (! $this->isVtpassEnabled()) {
            return $this->error('International Airtime Service Currently Unavailable');
        }

        $validated = $request->validate([
            'operator_id' => 'required|string',
            'product_type_id' => 'required|integer',
        ]);

        try {
            $response = $this->intlAirtimeService->getVariations(
                $validated['operator_id'],
                $validated['product_type_id']
            );
            $variations = $response['content'] ?? [];

            return $this->ok('Variations retrieved', $variations);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve variations', 500, $e->getMessage());
        }
    }

    public function purchase(InternationalAirtimePurchaseRequest $request)
    {
        $user = auth()->user();

        if (! $this->isVtpassEnabled()) {
            return $this->error('International Airtime Service Currently Unavailable');
        }

        $validated = $request->validated();

        if (! hash_equals((string) $user->sPin, (string) $validated['pin'])) {
            throw ValidationException::withMessages([
                'pin' => 'The provided PIN is incorrect.',
            ]);
        }

        $amount = $validated['amount'];
        unset($validated['pin']);

        $walletCheck = checkServiceWallet('vtpass', $amount);
        if ($walletCheck['status'] !== 'success' || ! $walletCheck['has_sufficient']) {
            return $this->error('Service unavailable at the moment. Please try again later.');
        }

        $transactionRef = generateTransactionRef();

        $transaction = VtpassTransaction::create([
            'user_id' => $user->sId,
            'service_type' => 'foreign-airtime',
            'transaction_ref' => $transactionRef,
            'amount' => $amount,
            'status' => TransactionStatus::PENDING,
            'request_payload' => $validated,
        ]);

        DB::beginTransaction();
        try {
            debitWallet(
                user: $user,
                amount: $amount,
                serviceName: 'International Airtime Purchase',
                serviceDesc: "International airtime purchase for {$validated['phone']}",
                transactionRef: $transactionRef,
                wrapInTransaction: false
            );

            $response = $this->intlAirtimeService->purchaseInternationalAirtime(
                $transactionRef,
                $validated
            );

            $responseCode = $response['code'] ?? '999';
            $vtpassRef = $response['content']['transactions']['transactionId'] ?? $response['requestId'] ?? null;
            $status = ($responseCode === '000') ? TransactionStatus::SUCCESS : TransactionStatus::FAILED;

            $transaction->update([
                'status' => $status,
                'vtpass_ref' => $vtpassRef,
                'response_payload' => $response,
                'error_code' => $responseCode,
                'error_message' => $response['response_description'] ?? 'Unknown Error',
            ]);

            if ($status === TransactionStatus::FAILED) {
                creditWallet(
                    user: $user,
                    amount: $amount,
                    serviceName: 'Wallet Refund',
                    serviceDesc: 'Refund for failed international airtime: ' . ($response['response_description'] ?? 'Unknown Error'),
                    transactionRef: null,
                    wrapInTransaction: false
                );
            }

            DB::commit();

            if ($status === TransactionStatus::SUCCESS) {
                ReferralBonusService::credit($user, $amount, ReferralBonusService::AIRTIME, $transactionRef);

                return $this->ok('International airtime purchased successfully', [
                    'reference' => $transactionRef,
                    'vtpass_response' => $response,
                ]);
            }

            return $this->error('International airtime purchase failed: ' . ($response['response_description'] ?? 'Unknown Error'), 400, $response);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('VTpass International Airtime purchase failed', ['error' => $e->getMessage()]);
            $transaction->update(['status' => TransactionStatus::FAILED, 'error_message' => $e->getMessage()]);

            return $this->error('Purchase failed', 500, $e->getMessage());
        }
    }

    private function isVtpassEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'vtpassStatus') === 'On' &&
                getConfigValue($config, 'vtpassInternationalAirtimeStatus') === 'On';
        }

        return $enabled;
    }
}
