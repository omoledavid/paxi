<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ForgotPasswordController extends Controller
{
    use ApiResponses;

    public function sendResetCodeEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:subscribers,sEmail',
        ],[
            'email.exists' => 'This email does not exist in our records.',
        ]);
        $user = User::query()->firstWhere('sEmail', $request->email);

        PasswordReset::where('email', $user->sEmail)->delete();
        $code = verificationCode(6);
        $password = new PasswordReset();
        $password->email = $user->sEmail;
        $password->token = $code;
        $password->created_at = \Carbon\Carbon::now();
        $password->save();

        $mailSent = sendVerificationCode($code, $user->sEmail, 'Reset Password');
        if(!$mailSent){
            $this->error('Something went wrong please try again later');
        }
        return $this->success('Reset code sent to your email',[
            'email' => $user->sEmail,
        ]);
    }

    public function verifyCode(Request $request)
    {
        $request->validate([
            'code' => 'required',
            'email' => 'required|exists:subscribers,sEmail',
        ],
        [
            'email.exists' => 'This email does not exist in our records.',
        ]);

        $code = $request->code;

        if (PasswordReset::where('token', $code)->where('email', $request->email)->count() != 1) {
            return $this->error('Verification code doesn\'t match');
        }

        return $this->ok('You can change your password.');
    }

    public function reset(Request $request)
    {

        $request->validate([
            'token' => 'required',
            'email' => 'required|exists:subscribers,sEmail',
            'password' => ['required', 'confirmed', Password::min(8)]
        ],[
            'email.exists' => 'This email does not exist in our records.',
        ]);
        $reset = PasswordReset::query()->where('token', $request->token)->orderBy('created_at', 'desc')->first();
        if (!$reset) {
            return $this->error('Invalid verification code');
        }

        $user = User::where('sEmail', $reset->email)->first();
        $user->sPass = bcrypt($request->password);
        $user->save();


        $reset->delete();

        return $this->ok('Password changed successfully', [
            'user' => new UserResource($user),
        ]);
    }


}
