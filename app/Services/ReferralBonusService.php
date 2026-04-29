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
            $result = DB::transaction(function () use ($referrer, $refTxRef, $serviceType, $transactionAmount, $bonusPercentage, $bonusAmount, $user, $txRef) {
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

                return [
                    'referrer_id' => $referrer->sId,
                    'bonus_percentage' => $bonusPercentage,
                    'bonus_amount' => $bonusAmount,
                    'service_type' => $serviceType,
                ];
            });

            // Run after commit so the fresh sRefWallet balance is visible
            static::checkAndCreditSignupBonus($user);
            static::autoPayoutIfThresholdMet($referrer);

            return $result;

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
            $result = DB::transaction(function () use ($referrer, $user, $signupBonus, $referrerUsername, $minAmount) {
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

            // Run after commit so the fresh sRefWallet balance is visible
            static::autoPayoutIfThresholdMet($referrer);

            return $result;

        } catch (\Exception $e) {
            Log::error('Referral signup bonus check failed', [
                'user_id' => $user->sId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Manually trigger a referral-wallet payout for a user.
     *
     * Rules:
     *  - Balance must be > 0
     *  - If threshold > 0, balance must be >= threshold
     *  - If threshold == 0, any positive balance can be paid out
     *
     * Returns an array with keys: success, message, amount (on success), threshold.
     *
     * @param  User  $user
     */
    public static function payout(User $user): array
    {
        $role       = (int) $user->sType;
        $commission = ReferralCommission::forRole($role) ?? ReferralCommission::forRole(0);
        $threshold  = $commission ? (float) $commission->auto_payout_threshold : 0.0;

        $refBalance = (float) DB::table('subscribers')
            ->where('sId', $user->sId)
            ->value('sRefWallet');

        if ($refBalance <= 0) {
            return [
                'success'   => false,
                'message'   => 'You have no commission balance to withdraw.',
                'threshold' => $threshold,
            ];
        }

        if ($threshold > 0 && $refBalance < $threshold) {
            return [
                'success'   => false,
                'message'   => sprintf(
                    'Your commission balance (₦%s) is below the minimum withdrawal threshold of ₦%s.',
                    number_format($refBalance, 2),
                    number_format($threshold, 2)
                ),
                'threshold' => $threshold,
            ];
        }

        try {
            DB::transaction(function () use ($user, $refBalance) {
                $affected = DB::table('subscribers')
                    ->where('sId', $user->sId)
                    ->where('sRefWallet', $refBalance) // optimistic lock
                    ->update([
                        'sWallet'    => DB::raw("sWallet + {$refBalance}"),
                        'sRefWallet' => DB::raw("sRefWallet - {$refBalance}"),
                    ]);

                if ($affected === 0) {
                    return; // concurrent sweep — already done
                }

                $subscriber  = DB::table('subscribers')->where('sId', $user->sId)->first();
                $newMainBal  = (float) $subscriber->sWallet;
                $oldMainBal  = $newMainBal - $refBalance;
                $payoutTxRef = 'PAYOUT-' . $user->sId . '-' . uniqid();

                DB::table('transactions')->insert([
                    'sId'         => $user->sId,
                    'transref'    => $payoutTxRef . '-D',
                    'servicename' => 'Referral Payout',
                    'servicedesc' => sprintf(
                        'Payout of N%s from referral wallet to main wallet',
                        number_format($refBalance, 2)
                    ),
                    'amount'      => $refBalance,
                    'status'      => 0,
                    'oldbal'      => $refBalance,
                    'newbal'      => 0,
                    'profit'      => 0,
                    'date'        => now(),
                    'created_at'  => now(),
                ]);

                DB::table('transactions')->insert([
                    'sId'         => $user->sId,
                    'transref'    => $payoutTxRef . '-C',
                    'servicename' => 'Referral Payout',
                    'servicedesc' => sprintf(
                        'Payout of N%s credited to main wallet from referral wallet',
                        number_format($refBalance, 2)
                    ),
                    'amount'      => $refBalance,
                    'status'      => 0,
                    'oldbal'      => $oldMainBal,
                    'newbal'      => $newMainBal,
                    'profit'      => 0,
                    'date'        => now(),
                    'created_at'  => now(),
                ]);

                Log::info('Referral manual payout executed', [
                    'user_id'      => $user->sId,
                    'amount_swept' => $refBalance,
                    'new_main_bal' => $newMainBal,
                    'payout_ref'   => $payoutTxRef,
                ]);
            });

            return [
                'success'   => true,
                'message'   => sprintf('₦%s has been moved to your main wallet.', number_format($refBalance, 2)),
                'amount'    => $refBalance,
                'threshold' => $threshold,
            ];
        } catch (\Exception $e) {
            Log::error('Referral manual payout failed', [
                'user_id' => $user->sId,
                'error'   => $e->getMessage(),
            ]);

            return [
                'success'   => false,
                'message'   => 'Payout failed. Please try again.',
                'threshold' => $threshold,
            ];
        }
    }

    /**
     * Auto-sweep the referrer's referral wallet to their main wallet
     * if the balance meets or exceeds the configured threshold.
     *
     * This runs synchronously on every bonus credit (no cron needed).
     * A threshold of 0 means the feature is disabled for that role.
     *
     * @param  User  $referrer
     */
    private static function autoPayoutIfThresholdMet(User $referrer): void
    {
        try {
            // 1. Get threshold for referrer's role (fall back to role 0)
            $referrerRole = (int) $referrer->sType;
            $commission = ReferralCommission::forRole($referrerRole)
                ?? ReferralCommission::forRole(0);

            if (! $commission) {
                return;
            }

            $threshold = (float) $commission->auto_payout_threshold;
            if ($threshold <= 0) {
                return; // Feature disabled
            }

            // 2. Re-read fresh referral wallet balance (after the credit just applied)
            $refBalance = (float) DB::table('subscribers')
                ->where('sId', $referrer->sId)
                ->value('sRefWallet');

            if ($refBalance < $threshold) {
                return; // Threshold not yet reached
            }

            // 3. Atomically sweep full referral balance to main wallet.
            //    The WHERE guard (sRefWallet = :balance) acts as an optimistic lock —
            //    if another process already swept, 0 rows are updated and we bail out.
            DB::transaction(function () use ($referrer, $refBalance) {
                $affected = DB::table('subscribers')
                    ->where('sId', $referrer->sId)
                    ->where('sRefWallet', $refBalance)
                    ->update([
                        'sWallet'    => DB::raw("sWallet + {$refBalance}"),
                        'sRefWallet' => DB::raw('sRefWallet - ' . $refBalance),
                    ]);

                if ($affected === 0) {
                    return; // Swept by a concurrent request — skip logging
                }

                // Fetch updated balances for transaction log
                $subscriber = DB::table('subscribers')
                    ->where('sId', $referrer->sId)
                    ->first();

                $newMainBalance = (float) $subscriber->sWallet;
                $oldMainBalance = $newMainBalance - $refBalance;
                $payoutTxRef    = 'AUTOPAYOUT-' . $referrer->sId . '-' . uniqid();

                // Log debit from referral wallet
                DB::table('transactions')->insert([
                    'sId'         => $referrer->sId,
                    'transref'    => $payoutTxRef . '-D',
                    'servicename' => 'Referral Payout',
                    'servicedesc' => sprintf(
                        'Auto payout of N%s from referral wallet to main wallet',
                        number_format($refBalance, 2)
                    ),
                    'amount'      => $refBalance,
                    'status'      => 0,
                    'oldbal'      => $refBalance,
                    'newbal'      => 0,
                    'profit'      => 0,
                    'date'        => now(),
                    'created_at'  => now(),
                ]);

                // Log credit to main wallet
                DB::table('transactions')->insert([
                    'sId'         => $referrer->sId,
                    'transref'    => $payoutTxRef . '-C',
                    'servicename' => 'Referral Payout',
                    'servicedesc' => sprintf(
                        'Auto payout of N%s credited to main wallet from referral wallet',
                        number_format($refBalance, 2)
                    ),
                    'amount'      => $refBalance,
                    'status'      => 0,
                    'oldbal'      => $oldMainBalance,
                    'newbal'      => $newMainBalance,
                    'profit'      => 0,
                    'date'        => now(),
                    'created_at'  => now(),
                ]);

                Log::info('Referral auto payout executed', [
                    'referrer_id'   => $referrer->sId,
                    'amount_swept'  => $refBalance,
                    'new_main_bal'  => $newMainBalance,
                    'payout_ref'    => $payoutTxRef,
                ]);
            });

        } catch (\Exception $e) {
            // Non-fatal — log and continue so the original bonus credit is not rolled back
            Log::error('Referral auto payout failed', [
                'referrer_id' => $referrer->sId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
