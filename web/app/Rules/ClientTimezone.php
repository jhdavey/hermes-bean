<?php

namespace App\Rules;

use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;

class ClientTimezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail("The {$attribute} field must be a valid IANA timezone or UTC offset.");

            return;
        }

        $timezone = trim($value);
        $ianaTimezones = timezone_identifiers_list(DateTimeZone::ALL);
        $isIanaTimezone = in_array($timezone, $ianaTimezones, true);
        $isUtcOffset = preg_match('/^[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00)$/', $timezone) === 1;

        if (! $isIanaTimezone && ! $isUtcOffset) {
            $fail("The {$attribute} field must be a valid IANA timezone or UTC offset.");
        }
    }
}
