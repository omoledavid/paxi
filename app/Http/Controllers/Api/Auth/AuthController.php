<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AccountLocked;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserLogin;
use App\Rules\NigerianPhone;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    use ApiResponses;

    public function register(Request $request)
    {
        $validatedData = request()->validate([
            'fname' => 'required',
            'lname' => 'required',
            'username' => 'required|alpha_dash|min:3|max:20|unique:subscribers,username',
            'sEmail' => 'required|email|unique:subscribers',
            'sPhone' => ['required', 'unique:subscribers', new NigerianPhone],
            'password' => ['required', Password::defaults(), 'confirmed'],
            'state' => 'nullable',
            'pin' => 'nullable|min:4',
            'referral' => 'nullable',
        ]);
        if (preg_match('/[^a-zA-Z0-9_ ]/', $request->fname)) {
            $response[] = 'No special characters or capital letters are allowed in the name field.';

            return $this->error($response, 400);
        }
        //check if phone number exist
        $phoneExist = User::where('sPhone', NigerianPhone::normalize($validatedData['sPhone']))->first();
        if ($phoneExist) {
            return $this->error('Phone number already exist.', 400);
        }
        $referralUsername = $validatedData['referral'] ?? null;
        if ($referralUsername) {
            $referrer = User::where('username', $referralUsername)->first();
            if (! $referrer) {
                return $this->error('The referral code (username) does not exist.', 422);
            }
        }

        $apiKey = apiKeyGen();
        $verCode = verificationCode(6);
        $userType = 0;

        $user = new User;
        $user->sFname = $validatedData['fname'];
        $user->sLname = $validatedData['lname'];
        $user->username = $validatedData['username'];
        $user->sEmail = $validatedData['sEmail'];
        $user->sPhone = NigerianPhone::normalize($validatedData['sPhone']);
        $user->sPass = passwordHash($validatedData['password']);
        $user->sState = $validatedData['state'];
        $user->sType = $userType;
        $user->sApiKey = $apiKey;
        $user->sReferal = $referralUsername;
        $user->sPin = $validatedData['pin'];
        $user->sVerCode = $verCode;
        $user->sVerCodeExpiry = now()->addMinutes(5);
        $user->sRegStatus = 3;
        $user->save();
        // refresh user
        $user->refresh();

        sendVerificationCode($verCode, $user->sEmail);
        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        return $this->ok('User registered successfully. Please verify your email address.', [
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'sPhone' => 'required',
            'password' => 'required|string|min:6',
        ], [
            'sPhone.required' => 'The phone number or email is required.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 6 characters.',
        ]);
        $password = $request->password;

        $user = User::query()->where('sPhone', $request->sPhone)->orWhere('sEmail', $request->sPhone)->first();

        if (! $user) {
            return $this->error(['Invalid credentials.'], 401);
        }

        if ($user->isLocked()) {
            $minutesUntilUnlock = max(1, $user->locked_until->diffInMinutes(now()));

            return $this->error([
                'Your account has been locked due to multiple failed login attempts. '.
                'Please reset your password or try again in '.$minutesUntilUnlock.' minutes.',
            ], 423);
        }

        $hashPassword = passwordHash($password);
        if (! hash_equals($hashPassword, $user->sPass)) {
            $failedAttempts = $user->incrementFailedAttempts();

            if ($failedAttempts >= 3) {
                $user->lockAccount();
                Mail::to($user->sEmail)->send(new AccountLocked($user));

                return $this->error([
                    'Your account has been locked due to multiple failed login attempts. '.
                    'Please reset your password or try again in 30 minutes.',
                ], 423);
            }

            return $this->error(['Invalid credentials.'], 401);
        }

        if ($user->sRegStatus == 1) {
            return $this->error(['Your account is blocked'], 403);
        }
        if ($user->sRegStatus == 2) {
            return $this->error(['Your account is pending verification.'], 403);
        }
        if ($user->sRegStatus == 3) {
            return $this->error(['Your account is not verified.'], 403);
        }

        $user->unlockAccount();

        // Device verification check
        $deviceHash = hash('sha256', $request->ip().'|'.$request->userAgent());

        $knownDevice = UserDevice::where('user_id', $user->sId)
            ->where('device_hash', $deviceHash)
            ->first();

        if ($knownDevice) {
            $knownDevice->update(['last_seen_at' => now()]);
        } else {
            $otp             = verificationCode(6);
            $verificationToken = Str::random(40);

            $user->update([
                'device_otp'                => $otp,
                'device_otp_expires_at'     => now()->addMinutes(10),
                'device_verification_token' => $verificationToken,
            ]);

            sendVerificationCode($otp, $user->sEmail, 'New Device Login Verification');

            return $this->ok('Device verification required.', [
                'requires_device_verification' => true,
                'verification_token'           => $verificationToken,
                'email'                        => $user->sEmail,
            ]);
        }

        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        return $this->ok(
            'Authenticated',
            [
                'token' => $token,
                'user' => [
                    'name' => $user->sFname.' '.$user->sLname,
                    'email' => $user->sEmail,
                ],
            ]
        );
    }

    public function verifyDevice(Request $request): JsonResponse
    {
        $request->validate([
            'verification_token' => ['required', 'string'],
            'otp_code'           => ['required', 'digits:6'],
        ]);

        $user = User::where('device_verification_token', $request->verification_token)->first();

        if (! $user) {
            return $this->error('Invalid or expired verification session.', 401);
        }

        if (! $user->device_otp_expires_at || now()->isAfter($user->device_otp_expires_at)) {
            return $this->error('The OTP has expired. Please request a new one.', 422);
        }

        if ($user->device_otp !== $request->otp_code) {
            return $this->error('Invalid OTP. Please try again.', 422);
        }

        // Register the device so future logins skip OTP
        $deviceHash = hash('sha256', $request->ip().'|'.$request->userAgent());

        UserDevice::updateOrCreate(
            ['user_id' => $user->sId, 'device_hash' => $deviceHash],
            [
                'user_agent'   => $request->userAgent(),
                'ip_address'   => $request->ip(),
                'last_seen_at' => now(),
            ]
        );

        // Clear pending OTP data
        $user->update([
            'device_otp'                => null,
            'device_otp_expires_at'     => null,
            'device_verification_token' => null,
        ]);

        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        return $this->ok('Authenticated', [
            'token' => $token,
            'user'  => [
                'name'  => $user->sFname.' '.$user->sLname,
                'email' => $user->sEmail,
            ],
        ]);
    }

    public function resendDeviceOtp(Request $request): JsonResponse
    {
        $request->validate([
            'verification_token' => ['required', 'string'],
        ]);

        $user = User::where('device_verification_token', $request->verification_token)->first();

        if (! $user) {
            return $this->error('Invalid or expired verification session.', 401);
        }

        // Prevent abuse: block resend if the current OTP was issued less than 2 minutes ago
        if ($user->device_otp_expires_at && $user->device_otp_expires_at->isAfter(now()->addMinutes(8))) {
            return $this->error('Please wait before requesting a new OTP.', 429);
        }

        $otp               = verificationCode(6);
        $verificationToken = Str::random(40);

        $user->update([
            'device_otp'                => $otp,
            'device_otp_expires_at'     => now()->addMinutes(10),
            'device_verification_token' => $verificationToken,
        ]);

        sendVerificationCode($otp, $user->sEmail, 'New Device Login Verification');

        return $this->ok('A new OTP has been sent to your email.', [
            'verification_token' => $verificationToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok('Logged out');
    }
}
