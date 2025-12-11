<?php

namespace App\Http\Requests\NelloBytes;

use Illuminate\Foundation\Http\FormRequest;

class BuySmileBundleRequest extends FormRequest
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
            'customer_id' => 'required|string',
            'mobile_number' => 'nullable|string',
            'mobile_network' => 'nullable|string',
            'package_code' => 'required|string',
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
            'customer_id.required' => 'The customer ID is required.',
            'package_code.required' => 'The package code is required.',
            'pin.required' => 'The PIN is required.',
            'pin.digits' => 'The PIN must be exactly 4 digits.',
            'pin.integer' => 'The PIN must be a number.',
        ];
    }
}

