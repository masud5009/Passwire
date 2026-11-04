<?php

namespace App\Rules;

use App\Services\Security\SsrfProtectionService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafePublicUrl implements ValidationRule
{
    protected SsrfProtectionService $ssrf;

    public function __construct(?SsrfProtectionService $ssrf = null)
    {
        $this->ssrf = $ssrf ?? new SsrfProtectionService();
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || empty($value)) {
            $fail("The {$attribute} must be a valid URL string.");
            return;
        }

        if (!$this->ssrf->isSafeUrl($value)) {
            $fail("The {$attribute} cannot point to a private IP, loopback, or cloud metadata endpoint.");
        }
    }
}

