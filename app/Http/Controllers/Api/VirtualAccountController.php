<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VirtualAccountService;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VirtualAccountController extends Controller
{
    use ApiResponses;

    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'virtualAccountName' => ['required', 'string', 'max:200'],
            'identityType'       => ['required', 'string', Rule::in(['personal', 'personal_nin', 'company'])],
            'licenseNumber'      => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! is_null($user->sBankNo)) {
            return $this->error('A virtual account already exists for this user.', 409);
        }

        $extra = $request->only(['virtualAccountName', 'identityType', 'licenseNumber']);

        $success = (new VirtualAccountService())->createVirtualAccount($user, $extra);

        if (! $success) {
            return $this->error('Virtual account creation is currently unavailable. Please try again later.', 503);
        }

        $user->refresh();

        return $this->ok('Virtual account created successfully.', [
            'bank_name'      => $user->sBankName,
            'account_number' => $user->sBankNo,
        ]);
    }
}
