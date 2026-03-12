<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\NigerianPhone;
use App\Services\ReferralBonusService;
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
            'phone' => ['nullable', new NigerianPhone],
            'state' => 'nullable|string',
        ]);

        // Filter out null values before updating
        $updateData = array_filter([
            'sFname' => $validatedData['fname'] ?? $user->sFname,
            'sLname' => $validatedData['lname'] ?? $user->sLname,
            'sPhone' => isset($validatedData['phone']) ? NigerianPhone::normalize($validatedData['phone']) : $user->sPhone,
            'sState' => $validatedData['state'] ?? $user->sState,
        ], fn ($value) => ! is_null($value)); // This prevents null values from overriding existing data

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

    public function changePhoneNumber(Request $request)
    {
        $user = auth()->user();

        // Security check: Only allow change if mobile NOT yet verified
        if ($user->sMobileVerified) {
            return $this->error('Cannot change phone number after verification.', 403);
        }

        // Validate the new phone number
        $validatedData = $request->validate([
            'new_phone' => ['required', new NigerianPhone, 'unique:subscribers,sPhone'],
        ], [
            'new_phone.unique' => 'This phone number is already in use.',
        ]);

        // Normalize the phone number
        $normalizedPhone = NigerianPhone::normalize($validatedData['new_phone']);

        // Update phone number and clear any existing verification codes
        $user->sPhone = $normalizedPhone;
        $user->sMobileVerCode = null;
        $user->sMobileVerCodeExpiry = null;
        $user->save();

        return $this->ok('Phone number updated successfully. Please verify your new number.', new UserResource($user));
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
            'amount.max' => 'Transfer amount exceeds maximum allowed limit',
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
            if (! $receiver) {
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
                'Wallet Transfer: Sent %s to %s. Fee: %s. New Balance: %s.',
                number_format($transferAmount, 2),
                htmlspecialchars($validatedData['email']),
                number_format($chargeAmount, 2),
                number_format($senderNewBal, 2)
            );

            $receiverDesc = sprintf(
                'Wallet Transfer: Received %s from %s. Fee: %s. New Balance: %s.',
                number_format($netAmount, 2),
                htmlspecialchars($user->sEmail),
                number_format($chargeAmount, 2),
                number_format($receiverNewBal, 2)
            );

            // 8. Update balances using wallet helpers (within the same transaction)
            $senderResult = debitWallet(
                $user,
                $transferAmount,
                TransactionType::WALLET_TRANSFER,
                $senderDesc,
                0,
                0,
                null,
                false,
                false
            );

            $receiverResult = creditWallet(
                $receiver,
                $netAmount,
                TransactionType::WALLET_TRANSFER,
                $receiverDesc,
                0,
                0,
                null,
                false,
                false
            );

            // 10. Commit the transaction after everything is successful
            DB::commit();

            //ReferralBonusService::credit($user, $transferAmount, ReferralBonusService::WALLET, $senderResult['transaction_ref']);

            // 11. Return minimal information in the response
            return $this->ok('Transfer completed successfully', [
                'balance' => $senderResult['user']->sWallet,
                'transaction_id' => $senderResult['transaction_ref'],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            // 12. Log the exception but don't expose details to the user
            Log::error('Wallet transfer failed: '.$e->getMessage(), [
                'user_id' => $user->sId,
                'request' => $request->except(['pin']),
            ]);

            return $this->error('Transaction failed. Please try again later.');
        }
    }

    public function checkUsername(string $username)
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $username) || strlen($username) < 3 || strlen($username) > 20) {
            return $this->error('Username must be 3-20 characters and contain only letters, numbers, and underscores.', 422);
        }

        $exists = User::where('username', $username)->exists();

        return $this->ok('success', [
            'available' => ! $exists,
        ]);
    }

    public function setUsername(Request $request)
    {
        $user = auth()->user();

        if ($user->username) {
            return $this->error('Username has already been set and cannot be changed.', 422);
        }

        $request->validate([
            'username' => 'required|alpha_dash|min:3|max:20|unique:subscribers,username',
        ]);

        $user->username = $request->username;
        $user->save();

        return $this->ok('Username set successfully.', new UserResource($user));
    }

    public function referralLeaderboard()
    {
        // Get weekly leaderboard (last 7 days) - using a subquery approach
        $weeklyLeaderboard = DB::table('subscribers as referred')
            ->select(
                'referred.sReferal as username',
                DB::raw('COUNT(*) as referral_count')
            )
            ->whereNotNull('referred.sReferal')
            ->where('referred.sReferal', '!=', '')
            ->where('referred.created_at', '>=', DB::raw('DATE_SUB(NOW(), INTERVAL 7 DAY)'))
            ->groupBy('referred.sReferal')
            ->orderByDesc('referral_count')
            ->limit(10)
            ->get()
            ->map(function ($entry, $index) {
                // Get referrer details separately
                $referrer = DB::table('subscribers')
                    ->where('username', $entry->username)
                    ->first();
                
                $email = $referrer?->sEmail ?? $entry->username;
                // Mask email for positions 4-10 (index 3-9)
                if ($index >= 3 && $email && str_contains($email, '@')) {
                    $parts = explode('@', $email);
                    $email = substr($parts[0], 0, 4) . '****@' . $parts[1];
                }
                
                return [
                    'rank' => $index + 1,
                    'username' => $entry->username,
                    'email' => $email,
                    'firstname' => $referrer?->sFname ?? '',
                    'lastname' => $referrer?->sLname ?? '',
                    'referral_count' => (int) $entry->referral_count,
                ];
            });

        // Get monthly leaderboard (last 30 days) - using a subquery approach
        $monthlyLeaderboard = DB::table('subscribers as referred')
            ->select(
                'referred.sReferal as username',
                DB::raw('COUNT(*) as referral_count')
            )
            ->whereNotNull('referred.sReferal')
            ->where('referred.sReferal', '!=', '')
            ->where('referred.created_at', '>=', DB::raw('DATE_SUB(NOW(), INTERVAL 30 DAY)'))
            ->groupBy('referred.sReferal')
            ->orderByDesc('referral_count')
            ->limit(10)
            ->get()
            ->map(function ($entry, $index) {
                // Get referrer details separately
                $referrer = DB::table('subscribers')
                    ->where('username', $entry->username)
                    ->first();
                
                $email = $referrer?->sEmail ?? $entry->username;
                // Mask email for positions 4-10 (index 3-9)
                if ($index >= 3 && $email && str_contains($email, '@')) {
                    $parts = explode('@', $email);
                    $email = substr($parts[0], 0, 4) . '****@' . $parts[1];
                }
                
                return [
                    'rank' => $index + 1,
                    'username' => $entry->username,
                    'email' => $email,
                    'firstname' => $referrer?->sFname ?? '',
                    'lastname' => $referrer?->sLname ?? '',
                    'referral_count' => (int) $entry->referral_count,
                ];
            });

        return $this->ok('success', [
            'weekly' => $weeklyLeaderboard,
            'monthly' => $monthlyLeaderboard,
        ]);
    }
}
