<?php

use App\Mail\SendVerificationCode;
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
