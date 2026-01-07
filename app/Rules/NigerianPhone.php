<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NigerianPhone implements ValidationRule
{
    /**
     * Valid Nigerian network prefixes
     */
    private const VALID_PREFIXES = [
        // MTN
        '0703',
        '0704',
        '0706',
        '0803',
        '0806',
        '0810',
        '0813',
        '0814',
        '0816',
        // Glo
        '0705',
        '0805',
        '0807',
        '0811',
        '0815',
        '0817',
        // Airtel
        '0701',
        '0708',
        '0802',
        '0808',
        '0812',
        // T2-Mobile
        '0909',
        '0809',
    ];

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Normalize the phone number: remove spaces, dashes, and other non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $value);

        // Handle international format: convert 234XXXXXXXXXX to 0XXXXXXXXXX
        if (str_starts_with($phone, '234') && strlen($phone) === 13) {
            $phone = '0' . substr($phone, 3);
        }

        // Check if the phone number has exactly 11 digits
        if (strlen($phone) !== 11) {
            $fail('The phone number must be 11 digits long.');
            return;
        }

        // Check if it starts with 0
        if (!str_starts_with($phone, '0')) {
            $fail('The :attribute must start with 0.');
            return;
        }

        // Extract the first 4 digits (network prefix)
        $prefix = substr($phone, 0, 4);

        // Check if the prefix is valid
        if (!in_array($prefix, self::VALID_PREFIXES, true)) {
            $fail('The phone number must be a valid Nigerian phone number (MTN, Glo, Airtel, or T2-Mobile).');
            return;
        }
    }

    /**
     * Normalize a phone number to the standard format
     * This can be used to convert 234XXXXXXXXXX to 0XXXXXXXXXX
     */
    public static function normalize(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Convert 234 format to 0 format
        if (str_starts_with($phone, '234') && strlen($phone) === 13) {
            $phone = '0' . substr($phone, 3);
        }

        return $phone;
    }
}

