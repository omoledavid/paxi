<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    use ApiResponses;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        return $this->ok('success', new UserResource($user));
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        //
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $user = auth()->user();

        // Validate request data
        $validatedData = $request->validate([
            'fname' => 'nullable|string',
            'lname' => 'nullable|string',
            'phone' => 'nullable|string',
            'state' => 'nullable|string',
        ]);

        // Filter out null values before updating
        $updateData = array_filter([
            'sFname' => $validatedData['fname'] ?? $user->sFname,
            'sLname' => $validatedData['lname'] ?? $user->sLname,
            'sPhone' => $validatedData['phone'] ?? $user->sPhone,
            'sState' => $validatedData['state'] ?? $user->sState,
        ], fn($value) => !is_null($value)); // This prevents null values from overriding existing data

        $user->update($updateData);

        // Return updated user data
        return $this->ok('User profile updated successfully', new UserResource($user));
    }

    public function changePassword(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|confirmed',
        ]);
        $oldPassword = passwordHash($validatedData['old_password']);
        if ($oldPassword == $user->sPass) {
            $user->sPass = passwordHash($validatedData['new_password']);
            $user->save();
            return $this->ok('Password changed successfully');
        } else {
            return $this->error('Incorrect password');
        }
    }
    public function changePin(Request $request)
    {
        $user = auth()->user();
        $validatedData = $request->validate([
            'old_pin' => 'required',
            'new_pin' => 'required|confirmed|digits:4|int',
        ]);
        if ($validatedData['old_pin'] == $user->sPin) {
            $user->sPin = $validatedData['new_pin'];
            $user->save();
            return $this->ok('Pin changed successfully');
        } else {
            return $this->error('Incorrect Pin');
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        //
    }
    public function walletTransfer(Request $request)
    {
        $user = auth()->user();
        $charge = gs('wallettowalletcharges');
        $senderOldBal = $user->sWallet;
        $validatedData = $request->validate([
            'amount' => 'required|numeric',
            'email' => 'required|email|exists:subscribers,sEmail',
            'pin' => 'required|digits:4|int',
        ],[
            'email.exists' => 'This email does not exist in our records'
        ]);
        if($user->sEmail == $validatedData['email']){
            return $this->error('Can\'t transfer to yourself');
        }
        if($user->sWallet < $validatedData['amount']) {
            return $this->error('Insufficient wallet balance');
        }
        if($user->sPin != $validatedData['pin']) {
            return $this->error('Incorrect pin');
        }
        $receiver = User::query()->where('sEmail', $validatedData['email'])->first();
        $receiverOldBal = $user->sWallet;
        $senderNewBal = $senderOldBal - $validatedData['amount'];
        $receiverNewBal = $receiverOldBal + ($validatedData['amount'] - $charge);
        $totalToPay = $validatedData['amount'] - $charge;
        $senderDesc = "Wallet To Wallet Transfer Of N{$validatedData['amount']} To User {$validatedData['email']}. Total Amount With Charges Is {$totalToPay}. New Balance Is {$senderNewBal}.";
        $receiverDesc = "Wallet To Wallet Transfer Of N{$validatedData['amount']} To User {$validatedData['email']}. Total Amount With Charges Is {$totalToPay}. New Balance Is {$receiverNewBal}.";
        DB::beginTransaction();
        try {
            $user->sWallet -= $validatedData['amount'];
            $user->save();
            TransactionLog($user->sId, generateTransactionRef(), TransactionType::WALLET_TRANSFER, $senderDesc, $validatedData['amount'],0,$receiverOldBal,$senderNewBal, 0);

            $receiver->sWallet += $validatedData['amount'];
            $receiver->save();
            TransactionLog($receiver->sId, generateTransactionRef(), TransactionType::WALLET_TRANSFER,$receiverDesc, $validatedData['amount'],0,$senderOldBal,$receiverNewBal, 0);

            DB::commit();
            return $this->ok('Wallet transfer completed', new UserResource($user));
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Something went wrong. Please try again.'.$e->getMessage(), );
        }

    }
}
