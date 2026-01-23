<?php

namespace App\Http\Controllers\Api\V1\Vtpass;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vtpass\SmilePurchaseRequest;
use App\Models\VtpassTransaction;
use App\Services\Vtpass\SmileService;
use App\Traits\ApiResponses;
use App\Models\ApiConfig; // [NEW]
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmileController extends Controller
{
    use ApiResponses;

    public function __construct(
        protected SmileService $smileService
    ) {
    }

    public function verify(Request $request)
    {
        if (!$this->isVtpassEnabled()) {
            return $this->error('Smile Service Currently Unavailable');
        }

        $validated = $request->validate([
            'serviceID' => 'required|string', // smile-direct
            'billersCode' => 'required|string', // Email or Phone
        ]);

        try {
            $response = $this->smileService->verifyEmail($validated['serviceID'], $validated['billersCode']);
            return $this->ok('Smile details verified', $response);
        } catch (\Exception $e) {
            return $this->error('Verification failed', 500, $e->getMessage());
        }
    }

    public function getBundles(Request $request)
    {
        $serviceID = $request->input('serviceID', 'smile-direct');
        try {
            $response = $this->smileService->getVariations($serviceID);
            return $this->ok('Bundles retrieved', $response);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve bundles', 500, $e->getMessage());
        }
    }

    public function purchase(SmilePurchaseRequest $request)
    {
        $user = auth()->user();

        if (!$this->isVtpassEnabled()) {
            return $this->error('Smile Service Currently Unavailable');
        }

        $validated = $request->validated();

        $amount = $validated['amount'];
        $transactionRef = generateTransactionRef();

        $transaction = VtpassTransaction::create([
            'user_id' => $user->sId,
            'service_type' => 'smile',
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
                serviceName: 'VTpass Smile Purchase',
                serviceDesc: "Smile purchase for {$validated['billersCode']}",
                transactionRef: $transactionRef,
                wrapInTransaction: false
            );

            $response = $this->smileService->purchaseBundle(
                $transactionRef,
                $validated['serviceID'],
                $validated['billersCode'],
                $validated['variation_code'],
                $amount,
                $validated['phone'] ?? $user->sPhone
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

            DB::commit();

            if ($status === TransactionStatus::SUCCESS) {
                return $this->ok('Smile purchase successful', $response);
            } else {
                return $this->error('Smile purchase failed: ' . ($response['response_description'] ?? 'Unknown Error'), 400, $response);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('VTpass Smile purchase failed', ['error' => $e->getMessage()]);
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
                (getConfigValue($config, 'vtpassSmileAirtimeStatus') === 'On' || getConfigValue($config, 'vtpassSmileDataStatus') === 'On');
        }

        return $enabled;
    }
}
