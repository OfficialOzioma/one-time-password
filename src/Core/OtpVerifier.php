<?php

declare(strict_types=1);

namespace Ozioma\Otp\Core;

use Ozioma\Otp\Contracts\ClockInterface;
use Ozioma\Otp\Contracts\OtpRepositoryInterface;
use Ozioma\Otp\Contracts\RateLimiterInterface;

final class OtpVerifier
{
    public function __construct(
        private readonly OtpRepositoryInterface $repository,
        private readonly OtpHasher $hasher,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly ClockInterface $clock,
        private readonly array $config,
    ) {
    }

    public function verify(string $identifier, string $purpose, string $token): bool
    {
        return $this->validate($identifier, $purpose, $token, false);
    }

    public function verifyAndConsume(string $identifier, string $purpose, string $token): bool
    {
        return $this->validate($identifier, $purpose, $token, true);
    }

    private function validate(string $identifier, string $purpose, string $token, bool $consume): bool
    {
        if (trim($identifier) === '' || strlen($identifier) > 1024 || !$this->validPurpose($purpose) || $token === '' || strlen($token) > 128) {
            return false;
        }

        $identifierHash = $this->hasher->identifierHash($identifier);
        $scopeKey = $this->hasher->scopeHash($identifierHash, $purpose);
        $rateConfig = $this->verificationSettings($purpose);

        if ($rateConfig['enabled'] && !$this->rateLimiter->consumeVerificationAttempt(
            $scopeKey,
            $rateConfig['max_attempts'],
            $rateConfig['decay_seconds'],
            $this->clock->now(),
        )) {
            return false;
        }

        // Calculate the candidate hash before loading the row. This keeps the
        // missing-record path closer in cost to an incorrect-token path.
        $submittedHash = $this->hasher->tokenHash($token, $identifierHash, $purpose);

        return $this->repository->transaction(function () use ($identifierHash, $purpose, $submittedHash, $consume): bool {
            $record = $this->repository->find($identifierHash, $purpose, true);

            if ($record === null) {
                hash_equals(str_repeat('0', 64), $submittedHash);
                return false;
            }

            $matches = hash_equals($record->tokenHash, $submittedHash);
            $now = $this->clock->now();
            if ($now >= $record->expiresAt) {
                $this->repository->delete($record);
                return false;
            }

            if ($record->attempts >= $record->maxAttempts) {
                return false;
            }

            if (!$matches) {
                $record->attempts++;
                $record->updatedAt = $now;
                $this->repository->save($record);
                return false;
            }

            if ($consume) {
                $this->repository->delete($record);
            }

            return true;
        });
    }

    private function verificationSettings(string $purpose): array
    {
        $global = $this->config['verification_rate_limit'] ?? [];
        $specific = $global['purposes'][$purpose] ?? [];

        return [
            'enabled' => (bool) ($global['enabled'] ?? true),
            'max_attempts' => $this->boundedInt($specific['max_attempts'] ?? $global['max_attempts'] ?? 20, 1, 100000),
            'decay_seconds' => $this->boundedInt($specific['decay_seconds'] ?? $global['decay_seconds'] ?? 3600, 1, 604800),
        ];
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException('Invalid OTP verification rate-limit configuration.');
        }

        return $value;
    }

    private function validPurpose(string $purpose): bool
    {
        return preg_match('/\A[a-z0-9_.-]{1,64}\z/D', $purpose) === 1;
    }
}
