<?php

namespace App\Http\Requests\Vtpass;

use Illuminate\Foundation\Http\FormRequest;

class AirtimePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serviceID' => 'required|string|in:mtn,glo,airtel,etisalat',
            'amount' => 'required|numeric|min:50',
            'phone' => 'required|string',
        ];
    }
}
