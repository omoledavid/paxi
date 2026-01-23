<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\NigerianPhone;
use App\Traits\ApiResponses;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuthorizationController extends Controller
{
    use ApiResponses;

    public function authorization()
    {
        $user = auth()->user();
        if ($user->sRegStatus === 3) {
            $verCode = $user->sVerCode;
            // Update expiry when resending
            $user->sVerCodeExpiry = Carbon::now()->addMinutes(1);
            $user->save();
            sendVerificationCode($verCode, $user->sEmail);

            return $this->ok('A verification code has been sent, kindly verify your account', new UserResource($user));
        } else {
            return $this->ok('User is already verified', new UserResource($user));
        }

    }

    public function sendVerifyCode(Request $request, $type)
    {
        $validatedData = $request->validate([
            'email' => 'required|string|email|max:255|exists:subscribers,sEmail',
        ]);
        $user = User::query()->where('sEmail', $validatedData['email'])->first();

        $user->sVerCode = verificationCode(6);
        $user->sVerCodeExpiry = Carbon::now()->addMinutes(1);
        $user->updated_at = Carbon::now();
        $user->save();
        $code = $user->sVerCode;
        sendVerificationCode($code, $user->sEmail);

        return $this->ok('Verification code has been sent', new UserResource($user));

    }

    public function emailVerification(Request $request)
    {
        $request->validate([
            'code' => 'required',
            'email' => 'required|email|exists:subscribers,sEmail',
        ]);

        // Verify code AND email match in a single query
        $user = User::query()
            ->where('sEmail', $request->email)
            ->where('sVerCode', $request->code)
            ->first();

        if (! $user) {
            return $this->error('Invalid verification code.');
        }

        // Check if the verification code has expired
        if ($user->sVerCodeExpiry && Carbon::now()->greaterThan($user->sVerCodeExpiry)) {
            return $this->error('Verification code has expired. Please request a new one.');
        }

        $user->sVerCode = 0;
        $user->sVerCodeExpiry = null;
        $user->sRegStatus = 0;
        $user->save();

        return $this->ok('Email verified successfully');
    }

    public function sendSmsVerificationCode(Request $request)
    {
        $validatedData = $request->validate([
            'phone' => ['required', new NigerianPhone, 'exists:subscribers,sPhone'],
        ], [
            'phone.exists' => 'This phone number does not exist in our records.',
        ]);

        $user = User::query()->where('sPhone', $validatedData['phone'])->first();

        // Generate 6-digit verification code
        $code = verificationCode(6);

        // Save code and expiry to database
        $user->sMobileVerCode = $code;
        $user->sMobileVerCodeExpiry = Carbon::now()->addMinutes(1);
        $user->updated_at = Carbon::now();
        $user->save();

        // Send SMS via GatewayAPI
        $smsSent = sendSmsVerificationCode($code, $user->sPhone);

        if (! $smsSent) {
            return $this->error('Failed to send SMS verification code. Please try again later.');
        }

        return $this->ok('SMS verification code has been sent to your phone', [
            'phone' => $user->sPhone,
        ]);
    }

    public function mobileVerification(Request $request)
    {
        $request->validate([
            'code' => 'required',
            'phone' => ['required', new NigerianPhone, 'exists:subscribers,sPhone'],
        ], [
            'phone.exists' => 'This phone number does not exist in our records.',
        ]);

        // Verify code AND phone match in a single query
        $user = User::query()
            ->where('sPhone', $request->phone)
            ->where('sMobileVerCode', $request->code)
            ->first();

        if (! $user) {
            return $this->error('Invalid verification code.');
        }

        // Check if the verification code has expired
        if ($user->sMobileVerCodeExpiry && Carbon::now()->greaterThan($user->sMobileVerCodeExpiry)) {
            return $this->error('Verification code has expired. Please request a new one.');
        }

        // Mark mobile as verified and clear verification code
        $user->sMobileVerCode = null;
        $user->sMobileVerCodeExpiry = null;
        $user->sMobileVerified = true;
        $user->save();

        return $this->ok('Mobile number verified successfully', new UserResource($user));
    }
}
