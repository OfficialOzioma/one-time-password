<?php

declare(strict_types=1);

namespace Ozioma\Otp\Domain;

use DateTimeImmutable;

final class OtpRecord
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $identifierHash,
        public readonly string $purpose,
        public readonly string $tokenHash,
        public readonly DateTimeImmutable $expiresAt,
        public int $attempts,
        public readonly int $maxAttempts,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
