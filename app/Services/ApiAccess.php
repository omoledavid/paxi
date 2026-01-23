<?php

namespace App\Services;

use App\Models\ApiConfig;
use App\Models\User;

class ApiAccess
{
    public function verifyMonnifyRef($email, $monnifyHash, $token)
    {
        $response = [];
        // Retrieve the Monnify secret from the environment or config
        $monnifySecret = ApiConfig::query()->where('name', 'monifySecrete')->first()->value('value');

        // Compute the hash
        $hash = $this->computeMonnifyHash($token, $monnifySecret);

        if ($hash === $monnifyHash) {
            // Retrieve subscriber details
            $subscriber = User::query()->where('sEmail', $email)->first();

            // Retrieve Monnify charges
            $charges = ApiConfig::query()->where('name', 'monifyCharges')->first()->value('value');

            if ($subscriber) {
                $response['status'] = 'success';
                $response['userid'] = $subscriber->sId;
                $response['name'] = $subscriber->sLname.' '.$subscriber->sFname;
                $response['balance'] = $subscriber->sWallet;
                $response['charges'] = $charges;

                return $response;
            } else {
                // Subscriber not found
                $response['status'] = 'fail';

                return $response;
            }
        }

        return false; // Hash mismatch
    }

    public function recordMonnifyTransaction($userId, $serviceName, $serviceDesc, $amount, $balance, $transRef, $status)
    {
        // Implement your transaction recording logic
        return true;
    }

    public function sendEmailNotification($serviceName, $message, $email)
    {
        // Implement your email notification logic
        return true;
    }

    private function computeMonnifyHash($token, $secret)
    {
        // Compute hash logic
        return hash('sha512', $token.$secret);
    }
}
