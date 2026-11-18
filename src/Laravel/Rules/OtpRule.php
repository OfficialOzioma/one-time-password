<?php

declare(strict_types=1);

namespace Ozioma\Otp\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ozioma\Otp\Core\OtpManager;

final class OtpRule implements ValidationRule
{
    public function __construct(
        private readonly string $identifier,
        private readonly string $purpose,
        private readonly ?string $message = null,
        private readonly bool $consumeOnSuccess = true,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (trim($this->identifier) === '' || !is_string($value) || $value === '') {
            $fail($this->message ?? 'The verification code is invalid or has expired.');
            return;
        }

        /** @var OtpManager $manager */
        $manager = app(OtpManager::class);
        $pending = $manager->for($this->identifier, $this->purpose);
        $valid = $this->consumeOnSuccess
            ? $pending->verifyAndConsume($value)
            : $pending->verify($value);

        if (!$valid) {
            $fail($this->message ?? 'The verification code is invalid or has expired.');
        }
    }
}
