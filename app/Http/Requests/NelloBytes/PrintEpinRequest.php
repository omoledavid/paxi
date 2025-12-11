<?php

namespace App\Http\Requests\NelloBytes;

use Illuminate\Foundation\Http\FormRequest;

class PrintEpinRequest extends FormRequest
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
            'mobile_network' => 'required|string|in:01,02,03,04',
            'value' => 'required|integer|in:100,200,500',
            'quantity' => 'required|integer|min:1|max:100',
            'callback_url' => 'nullable|url',
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
            'mobile_network.required' => 'The mobile network is required.',
            'mobile_network.in' => 'The mobile network must be one of 01, 02, 03, or 04.',
            'value.required' => 'The value is required.',
            'value.integer' => 'The value must be a valid number.',
            'value.in' => 'The value must be 100, 200, or 500.',
            'quantity.required' => 'The quantity is required.',
            'quantity.integer' => 'The quantity must be a valid number.',
            'quantity.min' => 'The quantity must be at least 1.',
            'quantity.max' => 'The quantity must not exceed 100.',
            'callback_url.url' => 'The callback URL must be a valid URL.',
            'pin.required' => 'The PIN is required.',
            'pin.digits' => 'The PIN must be exactly 4 digits.',
            'pin.integer' => 'The PIN must be a number.',
        ];
    }
}

