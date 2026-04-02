<?php

namespace App\Services;

use App\Models\ApiConfig;
use App\Models\User;
use App\Services\Palmpay\VirtualAccountService as PalmpayVirtualAccountService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VirtualAccountService
{
    private $config;

    public function __construct()
    {
        $this->config = ApiConfig::all();
    }

    /**
     * Create a virtual account for the user using the active gateway.
     *
     * Priority: PalmPay VA → Monnify → returns false if neither is active.
     */
    public function createVirtualAccount(User $user, array $extra = []): bool
    {
        if (getConfigValue($this->config, 'palmpayVirtualAccountStatus') === 'On') {
            return $this->createViaPalmpay($user, $extra);
        }

        if (getConfigValue($this->config, 'monifyStatus') === 'On') {
            return $this->createViaMonnify($user);
        }

        return false;
    }

    private function createViaPalmpay(User $user, array $extra = []): bool
    {
        try {
            $result = (new PalmpayVirtualAccountService())->createVirtualAccount($user, $extra);

            if (!empty($result['accountNumber'])) {
                $user->sBankName        = $result['bankName'];
                $user->sBankNo          = $result['accountNumber'];
                $user->sBankAccountName = $result['accountHolderName'] ?? null;
                $user->save();

                return true;
            }
        } catch (\Throwable $e) {
            Log::error('PalmPay VA creation failed', [
                'user_id' => $user->sId,
                'error'   => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function createViaMonnify(User $user): bool
    {
        $monnifyApi      = getConfigValue($this->config, 'monifyApi');
        $monnifySecret   = getConfigValue($this->config, 'monifySecrete');
        $monnifyContract = getConfigValue($this->config, 'monifyContract');
        $fullname        = $user->sFname . ' ' . $user->sLname;
        $apiKey          = base64_encode("$monnifyApi:$monnifySecret");

        $authResponse = Http::withHeaders(['Authorization' => "Basic {$apiKey}"])
            ->post('https://api.monnify.com/api/v1/auth/login');

        if ($authResponse->failed()) {
            Log::error('Monnify auth failed during VA creation', ['user_id' => $user->sId]);

            return false;
        }

        $accessToken = $authResponse->json('responseBody.accessToken');
        $ref         = uniqid() . rand(1000, 9000);

        $accountResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type'  => 'application/json',
        ])->post('https://api.monnify.com/api/v2/bank-transfer/reserved-accounts', [
            'accountReference'    => $ref,
            'accountName'         => $fullname,
            'currencyCode'        => 'NGN',
            'contractCode'        => $monnifyContract,
            'customerEmail'       => $user->sEmail,
            'bvn'                 => env('DEFAULT_BVN', ''),
            'customerName'        => $fullname,
            'getAllAvailableBanks' => false,
            'preferredBanks'      => ['035'],
        ]);

        if ($accountResponse->failed()) {
            Log::error('Monnify VA creation failed', [
                'user_id'  => $user->sId,
                'response' => $accountResponse->body(),
            ]);

            return false;
        }

        $data     = $accountResponse->json();
        $accounts = $data['responseBody']['accounts'] ?? [];

        if (($data['requestSuccessful'] ?? false) && !empty($accounts) && $accounts[0]['bankCode'] === '035') {
            $user->sBankName = $accounts[0]['bankName'];
            $user->sBankNo   = $accounts[0]['accountNumber'];
            $user->save();

            return true;
        }

        return false;
    }
}
