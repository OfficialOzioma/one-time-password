<?php

declare(strict_types=1);

namespace Ozioma\Otp\Exceptions;

final class OtpRateLimitException extends OtpException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message);
    }
}
