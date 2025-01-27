<?php

namespace App\Http\Controllers\Api\Auth;


use App\Http\Controllers\Controller;
use App\Models\ApiConfig;
use App\Models\User;
use App\Models\UserLogin;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    use ApiResponses;
    public function register(Request $request)
    {
        $validatedData = request()->validate([
            'fname' => 'required',
            'lname' => 'required',
            'sEmail' => 'required|email|unique:subscribers',
            'sPhone' => 'required|unique:subscribers|',
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
        $user->sEmail = $validatedData['sEmail'];
        $user->sPhone = $validatedData['sPhone'];
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
        //Generate User Login Token
        $randomToken = substr(str_shuffle("ABCDEFGHIJklmnopqrstvwxyz"), 0, 10);
        $userLoginToken = time() . $randomToken . mt_rand(100, 1000);

//        $userLogin = new UserLogin();
//        $userLogin->user = $user->sId;
//        $userLogin->token = $userLoginToken;
//        $userLogin->save();

        //create virtual account
        $apiConfig = ApiConfig::all();
        $monnifySecret = getConfigValue($apiConfig, 'monifySecrete');
        $monnifyApi = getConfigValue($apiConfig, 'monifyApi');
        $monifyStatus = getConfigValue($apiConfig, 'monifyStatus');
        $monnifyContract = getConfigValue($apiConfig, 'monifyContract');

        if($monifyStatus == 'On')
        {
            $this->createVirtualBankAccount($monnifyApi, $monnifySecret, $monnifyContract);
        }

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
    public function createVirtualBankAccount($monnifyApi, $monnifySecret, $monnifyContract)
    {
        $user = auth()->user();
        $fullname = $user->sFname . " " . $user->sLname;
        $accessKey = "$monnifyApi:$monnifySecret";
        $apiKey = base64_encode($accessKey);

        // Step 1: Get Authorization Data
        $authUrl = 'https://api.monnify.com/api/v1/auth/login';
        $accountCreationUrl = 'https://api.monnify.com/api/v2/bank-transfer/reserved-accounts';

        $authResponse = Http::withHeaders([
            'Authorization' => "Basic {$apiKey}",
        ])->post($authUrl);

        if ($authResponse->failed()) {
            throw new \Exception('Failed to authenticate with Monnify.');
        }

        $accessToken = $authResponse->json('responseBody.accessToken');
        $ref = uniqid() . rand(1000, 9000);

        // Step 2: Request Account Creation
        $accountCreationResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type' => 'application/json',
        ])->post($accountCreationUrl, [
            "accountReference" => $ref,
            "accountName" => $fullname,
            "currencyCode" => "NGN",
            "contractCode" => $monnifyContract,
            "customerEmail" => $user->sEmail,
            "bvn" => "22433145825",
            "customerName" => $fullname,
            "getAllAvailableBanks" => false,
            "preferredBanks" => ["035"],
        ]);

        if ($accountCreationResponse->failed()) {
            throw new \Exception('Failed to create virtual bank account.');
        }

        $accountData = $accountCreationResponse->json();

        // Step 3: Check and Save Account Details
        if ($accountData['requestSuccessful'] === true) {
            $accountName = $accountData['responseBody']['accountName'];
            $accounts = $accountData['responseBody']['accounts'];

            if (!empty($accounts) && $accounts[0]['bankCode'] === '035') {
                $wemaAccountNumber = $accounts[0]['accountNumber'];
                $wemaBankName = $accounts[0]['bankName'];

                $user->sBankName = $wemaBankName;
                $user->sBankNo = $wemaAccountNumber;
                $user->save();
            }
        }
    }

}
