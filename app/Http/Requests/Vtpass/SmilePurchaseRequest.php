<?php

namespace App\Http\Requests\Vtpass;

use Illuminate\Foundation\Http\FormRequest;

class SmilePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serviceID' => 'required|string',
            'billersCode' => 'required|string',
            'variation_code' => 'required|string',
            'amount' => 'required|numeric',
            'phone' => 'nullable|string',
        ];
    }
}
