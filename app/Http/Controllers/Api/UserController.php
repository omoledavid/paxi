<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        // 1. Add CSRF protection (Laravel includes this middleware by default)
        // Ensure 'web' middleware is applied to this route

        $user = auth()->user();
        $charge = gs('wallettowalletcharges') ?? 0;

        // 2. Improved validation with upper limit and more robust rules
        $validatedData = $request->validate([
            'amount' => 'required|numeric|min:1|max:50000',
            'email' => 'required|email|exists:subscribers,sEmail',
            'pin' => 'required|digits:4|int',
        ], [
            'email.exists' => 'This email does not exist in our records',
            'amount.max' => 'Transfer amount exceeds maximum allowed limit'
        ]);

        // 3. Additional validation checks
        if ($user->sEmail == $validatedData['email']) {
            return $this->error('Cannot transfer to yourself');
        }

        // 4. Lock the sender's record to prevent race conditions
        DB::beginTransaction();

        try {
            // Re-fetch user with lock to prevent race conditions
            $user = User::where('sId', $user->sId)->lockForUpdate()->first();
            $senderOldBal = $user->sWallet;

            if ($senderOldBal < $validatedData['amount']) {
                DB::rollBack();
                return $this->error('Insufficient wallet balance');
            }

            // 6. Lock the receiver's record as well to prevent race conditions
            $receiver = User::where('sEmail', $validatedData['email'])->lockForUpdate()->first();
            if (!$receiver) {
                DB::rollBack();
                return $this->error('Recipient not found');
            }

            $receiverOldBal = $receiver->sWallet;
            $transferAmount = $validatedData['amount'];
            $chargeAmount = $charge;
            $netAmount = $transferAmount - $chargeAmount;

            // Sanity check for negative values
            if ($netAmount <= 0) {
                DB::rollBack();
                return $this->error('Transfer amount too small to cover charges');
            }

            $senderNewBal = $senderOldBal - $transferAmount;
            $receiverNewBal = $receiverOldBal + $netAmount;

            // 7. Prepare sanitized descriptions for transaction logs
            $senderDesc = sprintf(
                "Wallet Transfer: Sent %s to %s. Fee: %s. New Balance: %s.",
                number_format($transferAmount, 2),
                htmlspecialchars($validatedData['email']),
                number_format($chargeAmount, 2),
                number_format($senderNewBal, 2)
            );

            $receiverDesc = sprintf(
                "Wallet Transfer: Received %s from %s. Fee: %s. New Balance: %s.",
                number_format($netAmount, 2),
                htmlspecialchars($user->sEmail),
                number_format($chargeAmount, 2),
                number_format($receiverNewBal, 2)
            );

            // 8. Update balances
            $user->sWallet = $senderNewBal;
            $user->save();

            $receiver->sWallet = $receiverNewBal;
            $receiver->save();

            // 9. Correct transaction logging with proper values
            $senderTxRef = generateTransactionRef();
            TransactionLog(
                $user->sId,
                $senderTxRef,
                TransactionType::WALLET_TRANSFER,
                $senderDesc,
                $transferAmount,
                0,
                $senderOldBal,
                $senderNewBal,
                0
            );

            $receiverTxRef = generateTransactionRef();
            TransactionLog(
                $receiver->sId,
                $receiverTxRef,
                TransactionType::WALLET_TRANSFER,
                $receiverDesc,
                $netAmount,
                0,
                $receiverOldBal,
                $receiverNewBal,
                0
            );

            // 10. Commit the transaction after everything is successful
            DB::commit();

            // 11. Return minimal information in the response
            return $this->ok('Transfer completed successfully', [
                'balance' => $user->sWallet,
                'transaction_id' => $senderTxRef
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            // 12. Log the exception but don't expose details to the user
            Log::error('Wallet transfer failed: ' . $e->getMessage(), [
                'user_id' => $user->sId,
                'request' => $request->except(['pin'])
            ]);

            return $this->error('Transaction failed. Please try again later.');
        }
    }
}
