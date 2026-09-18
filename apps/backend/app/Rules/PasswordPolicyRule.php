<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Reusable Password Policy Validation Rule (AUTH-006).
 *
 * Enforces configured minimum and maximum password length without
 * imposing arbitrary composition rules or performing silent truncation.
 */
class PasswordPolicyRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail("The {$attribute} must be a string.");

            return;
        }

        $minLength = (int) config('auth_security.password_min_length', 12);
        $maxLength = (int) config('auth_security.password_max_length', 128);

        $length = mb_strlen($value);

        if ($length < $minLength) {
            $fail("The {$attribute} must be at least {$minLength} characters.");

            return;
        }

        if ($length > $maxLength) {
            $fail("The {$attribute} may not be greater than {$maxLength} characters.");

            return;
        }
    }
}
