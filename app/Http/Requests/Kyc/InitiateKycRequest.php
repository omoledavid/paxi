<?php

namespace App\Http\Requests\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class InitiateKycRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Auth middleware handles core auth
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'template_id' => ['sometimes', 'integer'],
            'product_type' => ['required', 'string', 'in:verification,biometric_kyc,authentication'],
            'nin' => ['sometimes', 'string', 'regex:/^[0-9]{11}$/'],
            // 'user_id' => ['sometimes', 'exists:subscribers,sId'], // Only needed if admin initiating for user, but prompt says "Initiate (auth:sanctum)" usually means self.
        ];
    }
}
