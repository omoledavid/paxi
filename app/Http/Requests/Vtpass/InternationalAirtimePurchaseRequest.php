<?php

namespace App\Http\Requests\Vtpass;

use Illuminate\Foundation\Http\FormRequest;

class InternationalAirtimePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operator_id'    => 'required|string',
            'country_code'   => 'required|string',
            'product_type_id' => 'required|string',
            'variation_code'  => 'required|string',
            'phone'          => 'required|string',
            'amount'         => 'required|numeric|min:1',
            'email'          => 'required|email',
            'pin'            => 'required|string|size:4',
        ];
    }
}
