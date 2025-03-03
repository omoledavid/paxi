<?php

use App\Mail\SendVerificationCode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;


function apiKeyGen()
{
    return $apiKey = substr(str_shuffle("0123456789ABCDEFGHIJklmnopqrstvwxyzAbAcAdAeAfAgAhBaBbBcBdC1C23C3C4C5C6C7C8C9xix2x3"), 0, 60) . time();

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
    }catch (\Exception $exception){
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
    }else {
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

function generateTransactionRef(){
    $tranId=rand(1000,9999).time();
    return $tranId;
}

function passwordHash(string $password): string
{
    return substr(sha1(md5($password)), 3, 10);
}

function getConfigValue($list,$name){
    foreach($list AS $item){
        if($item->name == $name){return $item->value;}
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

    return $result['msg'] ?? 'Verification failed';
}
