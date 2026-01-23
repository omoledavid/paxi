<?php

namespace App\Http\Requests\Vtpass;

use Illuminate\Foundation\Http\FormRequest;

class DataPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serviceID' => 'required|string',
            'plan_code' => 'required|string',
            'phone' => 'required|string',
            'amount' => 'nullable|numeric',
        ];
    }
}
