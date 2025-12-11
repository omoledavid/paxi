<?php

namespace App\Http\Requests\NelloBytes;

use Illuminate\Foundation\Http\FormRequest;

class FundBettingRequest extends FormRequest
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
            'amount' => 'required|numeric|min:1',
            'pin' => 'required|digits:4|integer',
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
            'amount.required' => 'The amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 1.',
            'pin.required' => 'The PIN is required.',
            'pin.digits' => 'The PIN must be exactly 4 digits.',
            'pin.integer' => 'The PIN must be a number.',
        ];
    }
}

