<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

if (! function_exists('getWalletBalance')) {
    /**
     * Return the user's wallet balance.
     */
    function getWalletBalance(User $user, bool $refresh = false): float
    {
        if ($refresh) {
            return (float) User::where('sId', $user->sId)->value('sWallet');
        }

        return (float) $user->sWallet;
    }
}

if (! function_exists('debitWallet')) {
    /**
     * Debit the user's wallet and log the transaction.
     *
     * @return array{user: User, transaction_ref: string, old_balance: float, new_balance: float}
     */
    function debitWallet(
        User $user,
        float $amount,
        string $serviceName,
        string $serviceDesc,
        int $status = 0,
        float $profit = 0,
        ?string $transactionRef = null,
        bool $wrapInTransaction = true,
        bool $lockUser = true
    ): array {
        return mutateWallet(
            $user,
            $amount,
            $serviceName,
            $serviceDesc,
            true,
            $status,
            $profit,
            $transactionRef,
            $wrapInTransaction,
            $lockUser
        );
    }
}

if (! function_exists('creditWallet')) {
    /**
     * Credit the user's wallet and log the transaction.
     *
     * @return array{user: User, transaction_ref: string, old_balance: float, new_balance: float}
     */
    function creditWallet(
        User $user,
        float $amount,
        string $serviceName,
        string $serviceDesc,
        int $status = 1,
        float $profit = 0,
        ?string $transactionRef = null,
        bool $wrapInTransaction = true,
        bool $lockUser = true
    ): array {
        return mutateWallet(
            $user,
            $amount,
            $serviceName,
            $serviceDesc,
            false,
            $status,
            $profit,
            $transactionRef,
            $wrapInTransaction,
            $lockUser
        );
    }
}

if (! function_exists('mutateWallet')) {
    /**
     * Core wallet mutation logic used by debit/credit helpers.
     *
     * @return array{user: User, transaction_ref: string, old_balance: float, new_balance: float}
     */
    function mutateWallet(
        User $user,
        float $amount,
        string $serviceName,
        string $serviceDesc,
        bool $isDebit,
        int $status = 0,
        float $profit = 0,
        ?string $transactionRef = null,
        bool $wrapInTransaction = true,
        bool $lockUser = true
    ): array {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero');
        }

        $operation = function () use ($user, $amount, $serviceName, $serviceDesc, $isDebit, $status, $profit, $transactionRef, $lockUser) {
            $workingUser = $lockUser
                ? User::where('sId', $user->sId)->lockForUpdate()->first()
                : ($user->exists ? $user : User::where('sId', $user->sId)->first());

            if (! $workingUser) {
                throw new RuntimeException('User not found for wallet mutation');
            }

            $oldBal = (float) $workingUser->sWallet;

            if ($isDebit && $oldBal < $amount) {
                throw new RuntimeException('Insufficient wallet balance');
            }

            $newBal = $isDebit ? $oldBal - $amount : $oldBal + $amount;
            $workingUser->sWallet = $newBal;
            $workingUser->save();

            $txRef = $transactionRef ?: generateTransactionRef();

            TransactionLog(
                $workingUser->sId,
                $txRef,
                $serviceName,
                $serviceDesc,
                $amount,
                $status,
                $oldBal,
                $newBal,
                $profit
            );

            return [
                'user' => $workingUser,
                'transaction_ref' => $txRef,
                'old_balance' => $oldBal,
                'new_balance' => $newBal,
            ];
        };

        return $wrapInTransaction ? DB::transaction($operation) : $operation();
    }
}
