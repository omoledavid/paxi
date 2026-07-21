<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserBankAccount;
use App\Services\Palmpay\PayoutService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BankAccountController extends Controller
{
    use ApiResponses;

    /**
     * Fetch the list of supported banks from PalmPay.
     */
    public function getBanks(): JsonResponse
    {
        try {
            $service = new PayoutService();
            $banks = $service->queryBankList();

            return $this->ok('Banks retrieved successfully.', $banks);
        } catch (\Throwable $e) {
            Log::error('BankAccount: failed to fetch bank list', [
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to fetch bank list. Please try again.', 500);
        }
    }

    /**
     * Verify a bank account by querying PalmPay for the account holder name.
     */
    public function verifyAccount(Request $request): JsonResponse
    {
        $request->validate([
            'bank_code'      => ['required', 'string'],
            'account_number' => ['required', 'string', 'digits:10'],
        ]);

        try {
            $service = new PayoutService();
            $result = $service->queryBankAccount(
                $request->input('bank_code'),
                $request->input('account_number'),
            );

            return $this->ok('Account verified successfully.', $result);
        } catch (\Throwable $e) {
            Log::error('BankAccount: failed to verify account', [
                'bank_code'      => $request->input('bank_code'),
                'account_number' => $request->input('account_number'),
                'error'          => $e->getMessage(),
            ]);

            return $this->error('Failed to verify account. Please check the details and try again.', 422);
        }
    }

    /**
     * List the authenticated user's saved bank accounts.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = UserBankAccount::forUser($request->user()->sId)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return $this->ok('Bank accounts retrieved.', $accounts);
    }

    /**
     * Save a new bank account for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can_add_bank_account) {
            return $this->error('You are not permitted to add bank accounts.', 403);
        }

        $request->validate([
            'bank_code'      => ['required', 'string'],
            'bank_name'      => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'digits:10'],
            'account_name'   => ['required', 'string', 'max:255'],
        ]);

        // Check for duplicates (including soft-deleted)
        $existing = UserBankAccount::withTrashed()
            ->where('user_id', $user->sId)
            ->where('bank_code', $request->input('bank_code'))
            ->where('account_number', $request->input('account_number'))
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
                $existing->update([
                    'bank_name'    => $request->input('bank_name'),
                    'account_name' => $request->input('account_name'),
                ]);

                return $this->ok('Bank account restored successfully.', $existing->fresh());
            }

            return $this->error('This bank account already exists.', 409);
        }

        // If this is the first account, make it default
        $isFirst = UserBankAccount::forUser($user->sId)->count() === 0;

        $account = UserBankAccount::create([
            'user_id'        => $user->sId,
            'bank_code'      => $request->input('bank_code'),
            'bank_name'      => $request->input('bank_name'),
            'account_number' => $request->input('account_number'),
            'account_name'   => $request->input('account_name'),
            'is_default'     => $isFirst,
        ]);

        return $this->ok('Bank account added successfully.', $account, 201);
    }

    /**
     * Set a bank account as the default.
     */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->sId;
        $account = UserBankAccount::forUser($userId)->find($id);

        if (! $account) {
            return $this->error('Bank account not found.', 404);
        }

        // Unset all other defaults for this user
        UserBankAccount::forUser($userId)->where('id', '!=', $id)->update(['is_default' => false]);

        $account->update(['is_default' => true]);

        return $this->ok('Default bank account updated.', $account->fresh());
    }

    /**
     * Remove a saved bank account.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = UserBankAccount::forUser($request->user()->sId)->find($id);

        if (! $account) {
            return $this->error('Bank account not found.', 404);
        }

        $account->delete();

        return $this->ok('Bank account removed successfully.');
    }
}
