<?php

namespace App\Http\Controllers\Api\Auth;


use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponses;
    public function register(Request $request)
    {
        $validatedData = request()->validate([
            'fname' => 'required',
            'lname' => 'required',
            'email' => 'required|email|unique:subscribers',
            'phone' => 'required|unique:subscribers|',
            'password' => 'required|string|min:6|confirmed',
            'state' => 'required',
            'pin' => 'required',
            'referral' => 'nullable'
        ]);
        if (preg_match("/[^a-zA-Z0-9_ ]/", $request->fname)) {
            $response[] = 'No special characters or capital letters are allowed in the name field.';
            return $this->error($response, 400);
        }
        $apiKey = apiKeyGen();
        $verCode = verificationCode(4);
        $userType = 0;

        $user = new User();
        $user->sFname = $validatedData['fname'];
        $user->sLname = $validatedData['lname'];
        $user->sEmail = $validatedData['email'];
        $user->sPhone = $validatedData['phone'];
        $user->sPass = passwordHash($validatedData['password']);
        $user->sState = $validatedData['state'];
        $user->sType = $userType;
        $user->sApiKey = $apiKey;
        $user->sReferal = $validatedData['referral'];
        $user->sPin = $validatedData['pin'];
        $user->sVerCode = $verCode;
        $user->sRegStatus = 3;
        $user->save();

        sendVerificationCode($verCode, $user->sEmail);
        $token = $user->createToken('auth_token',['*'])->plainTextToken;


//        $token = $user->createToken('auth_token',['*'], now()->addDay())->plainTextToken;
        return $this->ok('User registered successfully. Please verify your email address.', [
            'user' => $user,
            'token' => $token
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'sPhone' => 'required|exists:subscribers,sPhone',
            'password' => 'required|string|min:6'
        ], [
            'sPhone.required' => 'The phone number is required.',
            'sPhone.exists' => 'This phone number is not registered.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 6 characters.',
        ]);
        $password = $request->password;

        // Retrieve the user by phone number
        $user = User::firstWhere('sPhone', $request->sPhone);

        // Check if user exists
        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Verify the password
        $hashPassword = passwordHash($password);
        if ($hashPassword !== $user->sPass) {
            return $this->error(['Wrong password.'], 401);
        }

        // Generate the token
        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        // Return the response with the token and user details
        return $this->ok(
            'Authenticated',
            [
                'token' => $token,
                'user' => [
                    'name' => $user->sFname. ' ' . $user->sLname,
                    'email' => $user->sEmail,
                ],
            ]
        );
    }


    public function logout(Request $request):JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return $this->ok('Logged out');
    }
}
