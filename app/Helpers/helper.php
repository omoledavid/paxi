<?php

use App\Mail\SendVerificationCode;
use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;


function apiKeyGen()
{
    return Str::random(64);
}
function verificationCode($length)
{
    if ($length == 0) {
        return 0;
    }

    $min = pow(10, $length - 1);
    $max = (int) ($min - 1) . '9';
    return random_int($min, $max);
}
function sendVerificationCode($code, $email, $subject = 'Account Verification')
{
    try {
        Mail::to($email)->send(new SendVerificationCode($code, $subject));
    } catch (\Exception $exception) {
        return false;
    }
}

function verifyNetwork(string $phone, string $selectedNetwork = ''): array
{
    $phonePrefix = substr($phone, 0, 4);

    if (empty($phone) || strlen($phone) < 6) {
        return [
            'status' => false,
            'identifiedNetwork' => '',
            'message' => 'Phone number is too short or empty.',
        ];
    }

    // Define network patterns
    $patterns = [
        'MTN' => '/0702|0704|0803|0806|0703|0706|0813|0816|0810|0814|0903|0906|0913/',
        'GLO' => '/091|0805|0807|0705|0815|0811|0905/',
        'GIFTING' => '/0702|0704|0803|0806|0703|0706|0813|0816|0810|0814|0903|0906|0913/',
        'AIRTEL' => '/0802|0808|0708|0812|0701|0901|0902|0907|0912/',
        '9MOBILE' => '/0809|0818|0817|0908|0909/',
        'NTEL' => '/0804/',
    ];

    // Identify the network
    $identifiedNetwork = 'Unable to identify network!';
    foreach ($patterns as $network => $pattern) {
        if (preg_match($pattern, $phonePrefix)) {
            $identifiedNetwork = $network;
            break;
        }
    }

    // Handle "ETISALAT" as "9MOBILE"
    if (strtoupper($selectedNetwork) === 'ETISALAT') {
        $selectedNetwork = '9MOBILE';
    } else {
        $selectedNetwork = $identifiedNetwork;
    }

    // Determine if the identified network matches the selected network
    $isMatch = strtoupper($identifiedNetwork) === strtoupper($selectedNetwork);

    return [
        'status' => $isMatch,
        'identifiedNetwork' => $identifiedNetwork,
        'message' => $isMatch
            ? "Network verified successfully as $identifiedNetwork."
            : "Warning: Identified network ($identifiedNetwork) does not match the selected network ($selectedNetwork).",
    ];
}

function generateTransactionRef()
{
    $tranId = rand(1000, 9999) . time();
    return $tranId;
}

function passwordHash(string $password): string
{
    return substr(sha1(md5($password)), 3, 10);
}

function getConfigValue($list, $name)
{
    foreach ($list as $item) {
        if ($item->name == $name) {
            return $item->value;
        }
    }
}
function validateMeterNumber($provider, $meternumber, $metertype, $apiKey)
{
    $siteUrl = env('FRONTEND_URL');

    $response = Http::withHeaders([
        'Content-Type' => 'application/json',
        'Token' => "Token $apiKey",
    ])->post("$siteUrl/api838190/electricity/verify/", [
                'provider' => $provider,
                'meternumber' => $meternumber,
                'metertype' => $metertype,
            ]);

    $result = $response->json();

    return $result;
}
function TransactionLog(
    int $user_id,
    string $transRef,
    string $serviceName,
    string $serviceDesc,
    float $amount,
    int $status,
    float $oldBal,
    float $newBal,
    float $profit = 1
) {
    // Example logic for logging the transaction
    DB::table('transactions')->insert([
        'sId' => $user_id,
        'transref' => $transRef,
        'servicename' => $serviceName,
        'servicedesc' => $serviceDesc,
        'amount' => $amount,
        'status' => $status,
        'oldbal' => $oldBal,
        'newbal' => $newBal,
        'profit' => $profit,
        'date' => now(),
        'created_at' => now(),
    ]);

    return true; // or return the inserted transaction details
}
function gs($key = null)
{
    $general = Cache::get('GeneralSetting');
    if (!$general) {
        $general = GeneralSetting::first();
        Cache::put('GeneralSetting', $general);
    }
    if ($key) {
        return @$general->$key;
    }

    return $general;
}
