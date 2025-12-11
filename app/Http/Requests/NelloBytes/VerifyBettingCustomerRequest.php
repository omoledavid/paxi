<?php

namespace App\Http\Requests\NelloBytes;

use Illuminate\Foundation\Http\FormRequest;

class VerifyBettingCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_code' => 'required|string',
            'customer_id' => 'required|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_code.required' => 'The betting company code is required.',
            'customer_id.required' => 'The customer ID is required.',
        ];
    }
}

