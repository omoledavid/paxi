<?php

namespace App\Services;

use App\Models\ReferralCommission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralBonusService
{
    /**
     * Service type constants matching referral_commissions column prefixes.
     */
    public const AIRTIME = 'airtime';
    public const DATA = 'data';
    public const WALLET = 'wallet';
    public const CABLE = 'cable';
    public const EXAM = 'exam';
    public const METER = 'meter';
    public const UPGRADE = 'upgrade';
    public const BETTING = 'betting';
    public const EPIN = 'epin';

    /**
     * Credit referral bonus to the referrer after a successful transaction.
     *
     * @param  User  $user  The user who made the transaction
     * @param  float  $transactionAmount  The transaction amount
     * @param  string  $serviceType  One of the service type constants (airtime, data, wallet, cable, exam, meter, upgrade)
     * @param  string|null  $transactionRef  Optional transaction reference for logging
     * @return array|null Returns bonus details if credited, null if no referrer or no bonus
     */
    public static function credit(
        User $user,
        float $transactionAmount,
        string $serviceType,
        ?string $transactionRef = null
    ): ?array {
        try {
            // 1. Check if user has a referrer
            $referrerUsername = $user->sReferal;
            if (empty($referrerUsername)) {
                return null;
            }

            // 2. Find the referrer
            $referrer = User::where('username', $referrerUsername)->first();
            if (! $referrer) {
                Log::warning('Referral bonus: referrer not found', [
                    'user_id' => $user->sId,
                    'referrer_username' => $referrerUsername,
                ]);
                return null;
            }

            // 3. Generate transaction reference early to check for duplicates
            $txRef = $transactionRef ?: uniqid('REF-', true);
            $refTxRef = 'REF-' . $txRef;

            // 4. Check if this transaction has already been credited
            $existingBonus = DB::table('transactions')
                ->where('transref', $refTxRef)
                ->where('servicename', 'Referral Bonus')
                ->exists();

            if ($existingBonus) {
                Log::info('Referral bonus already credited for this transaction', [
                    'transaction_ref' => $txRef,
                    'user_id' => $user->sId,
                ]);
                return null;
            }

            // 5. Get the bonus percentage based on the REFERRER's role
            $referrerRole = (int) $referrer->sType;
            $bonusPercentage = ReferralCommission::getBonusForService($referrerRole, $serviceType);

            if ($bonusPercentage <= 0) {
                return null;
            }

            // 6. Calculate bonus amount
            $bonusAmount = round(($bonusPercentage / 100) * $transactionAmount, 2);

            if ($bonusAmount <= 0) {
                return null;
            }

            // 7. Credit the referrer's referral wallet and log transaction
            return DB::transaction(function () use ($referrer, $refTxRef, $serviceType, $transactionAmount, $bonusPercentage, $bonusAmount, $user, $txRef) {
                // Capture current balance from database
                $currentBalance = (float) DB::table('subscribers')
                    ->where('sId', $referrer->sId)
                    ->value('sRefWallet');
                
                // Credit the referrer's referral wallet (sRefWallet)
                DB::table('subscribers')
                    ->where('sId', $referrer->sId)
                    ->increment('sRefWallet', $bonusAmount);

                // Log the referral bonus transaction
                DB::table('transactions')->insert([
                    'sId' => $referrer->sId,
                    'transref' => $refTxRef,
                    'servicename' => 'Referral Bonus',
                    'servicedesc' => sprintf(
                        'Referral bonus (%.1f%%) from %s %s transaction by %s',
                        $bonusPercentage,
                        $serviceType,
                        number_format($transactionAmount, 2),
                        $user->username ?? $user->sPhone
                    ),
                    'amount' => $bonusAmount,
                    'status' => 0, // 0 = success
                    'oldbal' => $currentBalance,
                    'newbal' => $currentBalance + $bonusAmount,
                    'profit' => 0,
                    'date' => now(),
                    'created_at' => now(),
                ]);

                Log::info('Referral bonus credited', [
                    'referrer_id' => $referrer->sId,
                    'referrer_username' => $referrer->sReferal,
                    'user_id' => $user->sId,
                    'service_type' => $serviceType,
                    'transaction_amount' => $transactionAmount,
                    'bonus_percentage' => $bonusPercentage,
                    'bonus_amount' => $bonusAmount,
                    'transaction_ref' => $txRef,
                ]);

                // Also check if the signup bonus conditions are now met
                static::checkAndCreditSignupBonus($user);

                return [
                    'referrer_id' => $referrer->sId,
                    'bonus_percentage' => $bonusPercentage,
                    'bonus_amount' => $bonusAmount,
                    'service_type' => $serviceType,
                ];
            });

        } catch (\Exception $e) {
            Log::error('Referral bonus credit failed', [
                'user_id' => $user->sId,
                'service_type' => $serviceType,
                'amount' => $transactionAmount,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if a referred user has met the conditions for the signup bonus
     * and credit the referrer if so.
     *
     * Conditions:
     * 1. The referred user must have kyc_status = 'approved'
     * 2. The referred user's total successful transactions must be >= min_transaction_amount
     * 3. The signup bonus must not have already been credited for this user
     *
     * @param  User  $user  The referred user whose activity triggers the check
     * @return array|null Returns bonus details if credited, null otherwise
     */
    public static function checkAndCreditSignupBonus(User $user): ?array
    {
        try {
            // 1. Has a referrer?
            $referrerUsername = $user->sReferal;
            if (empty($referrerUsername)) {
                return null;
            }

            // 2. KYC approved?
            if ($user->kyc_status !== 'approved') {
                return null;
            }

            // 3. Find the referrer
            $referrer = User::where('username', $referrerUsername)->first();
            if (! $referrer) {
                return null;
            }

            // 4. Get commission settings for referrer's role
            $referrerRole = (int) $referrer->sType;
            $commission = ReferralCommission::forRole($referrerRole);
            if (! $commission) {
                $commission = ReferralCommission::forRole(0);
            }
            if (! $commission) {
                return null;
            }

            $signupBonus = (float) $commission->referral_signup_bonus;
            $minAmount = (float) $commission->min_transaction_amount;

            if ($signupBonus <= 0) {
                return null;
            }

            // 5. Check accumulated successful transactions for the referred user
            if ($minAmount > 0) {
                $totalTransactions = (float) DB::table('transactions')
                    ->where('sId', $user->sId)
                    ->where('status', 0) // 0 = success
                    ->whereNotIn('servicename', ['Referral Bonus', 'Wallet Credit', 'Refund', 'Debit'])
                    ->sum('amount');

                if ($totalTransactions < $minAmount) {
                    return null;
                }
            }

            // 6. Atomic update to prevent race condition - mark as credited first
            $affected = DB::table('subscribers')
                ->where('sId', $user->sId)
                ->where('referral_bonus_credited', 0)
                ->update(['referral_bonus_credited' => 1]);

            // If no rows were affected, the bonus was already credited
            if ($affected === 0) {
                return null;
            }

            // 7. Credit the referrer's referral wallet and log transaction
            return DB::transaction(function () use ($referrer, $user, $signupBonus, $referrerUsername, $minAmount) {
                // Capture current balance from database
                $currentBalance = (float) DB::table('subscribers')
                    ->where('sId', $referrer->sId)
                    ->value('sRefWallet');
                
                // Credit the referrer's referral wallet
                DB::table('subscribers')
                    ->where('sId', $referrer->sId)
                    ->increment('sRefWallet', $signupBonus);

                // Log the transaction
                $txRef = 'SIGNUP-REF-' . $user->sId . '-' . uniqid();
                DB::table('transactions')->insert([
                    'sId' => $referrer->sId,
                    'transref' => $txRef,
                    'servicename' => 'Referral Signup Bonus',
                    'servicedesc' => sprintf(
                        'Referral signup bonus of N%s for referring %s (KYC approved + min transactions met)',
                        number_format($signupBonus, 2),
                        $user->username ?? $user->sEmail
                    ),
                    'amount' => $signupBonus,
                    'status' => 0, // 0 = success
                    'oldbal' => $currentBalance,
                    'newbal' => $currentBalance + $signupBonus,
                    'profit' => 0,
                    'date' => now(),
                    'created_at' => now(),
                ]);

                Log::info('Referral signup bonus credited', [
                    'referrer_id' => $referrer->sId,
                    'referrer_username' => $referrerUsername,
                    'referred_user_id' => $user->sId,
                    'bonus_amount' => $signupBonus,
                    'min_transaction_amount' => $minAmount,
                ]);

                return [
                    'referrer_id' => $referrer->sId,
                    'bonus_amount' => $signupBonus,
                    'type' => 'signup_bonus',
                ];
            });

        } catch (\Exception $e) {
            Log::error('Referral signup bonus check failed', [
                'user_id' => $user->sId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
