<?php

declare(strict_types=1);

namespace Ozioma\Otp\Contracts;

use DateTimeImmutable;

interface RateLimiterInterface
{
    /**
     * Atomically reserve a generation allowance.
     * Return null on success or the number of seconds until retry is allowed.
     */
    public function consumeGenerationAllowance(
        string $scopeKey,
        int $cooldownSeconds,
        int $maximumGenerations,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): ?int;

    /** Atomically count a verification request. Return false when over limit. */
    public function consumeVerificationAttempt(
        string $scopeKey,
        int $maximumAttempts,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): bool;

    public function pruneExpired(DateTimeImmutable $now, int $limit = 1000): int;
}
