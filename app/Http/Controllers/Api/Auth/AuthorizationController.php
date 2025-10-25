<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use function App\Http\Controllers\Api\v1\verifyG2fa;

class AuthorizationController extends Controller
{
    use ApiResponses;

    public function authorization()
    {
        $user = auth()->user();
        if ($user->sRegStatus === 3) {
            $verCode = $user->sVerCode;
            // Update expiry when resending
            $user->sVerCodeExpiry = Carbon::now()->addMinutes(5);
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
        $user->sVerCodeExpiry = Carbon::now()->addMinutes(5);
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
            'email' => 'required|email|exists:subscribers,sEmail'
        ]);

        // Verify code AND email match in a single query
        $user = User::query()
            ->where('sEmail', $request->email)
            ->where('sVerCode', $request->code)
            ->first();

        if (!$user) {
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

    public function mobileVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'remark' => 'validation_error',
                'status' => 'error',
                'message' => ['error' => $validator->errors()->all()],
            ]);
        }

        $user = auth()->user();
        if ($user->ver_code == $request->code) {
            $user->sv = 1;
            $user->ver_code = null;
            $user->ver_code_send_at = null;
            $user->save();
            $notify[] = 'Mobile verified successfully';
            return response()->json([
                'remark' => 'mobile_verified',
                'status' => 'success',
                'message' => ['success' => $notify],
            ]);
        }
        $notify[] = 'Verification code doesn\'t match';
        return response()->json([
            'remark' => 'validation_error',
            'status' => 'error',
            'message' => ['error' => $notify],
        ]);
    }

}
