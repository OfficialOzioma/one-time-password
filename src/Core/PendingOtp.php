<?php

declare(strict_types=1);

namespace Ozioma\Otp\Core;

use InvalidArgumentException;
use Ozioma\Otp\Contracts\ClockInterface;
use Ozioma\Otp\Contracts\LockManagerInterface;
use Ozioma\Otp\Contracts\OtpRepositoryInterface;
use Ozioma\Otp\Contracts\RateLimiterInterface;
use Ozioma\Otp\Domain\GeneratedOtp;
use Ozioma\Otp\Domain\OtpRecord;
use Ozioma\Otp\Exceptions\OtpRateLimitException;

final class PendingOtp
{
    public function __construct(
        private readonly string $identifier,
        private readonly string $purpose,
        private array $settings,
        private readonly OtpRepositoryInterface $repository,
        private readonly OtpGenerator $generator,
        private readonly OtpHasher $hasher,
        private readonly OtpVerifier $verifier,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly LockManagerInterface $lockManager,
        private readonly ClockInterface $clock,
        private readonly array $config,
    ) {
    }

    public function validFor(int $minutes): self { $this->settings['validity'] = $minutes; return $this; }
    public function length(int $length): self { $this->settings['length'] = $length; return $this; }
    public function numeric(): self { $this->settings['type'] = 'numeric'; return $this; }
    public function alphanumeric(?string $alphabet = null): self
    {
        $this->settings['type'] = 'alphanumeric';
        if ($alphabet !== null) { $this->settings['alphabet'] = $alphabet; }
        return $this;
    }
    public function maxAttempts(int $attempts): self { $this->settings['max_attempts'] = $attempts; return $this; }
    public function resendAfter(int $seconds): self { $this->settings['resend_cooldown'] = $seconds; return $this; }
    public function maxGenerations(int $maximum, int $windowMinutes): self
    {
        $this->settings['max_generations'] = $maximum;
        $this->settings['generation_window'] = $windowMinutes;
        return $this;
    }

    public function generate(): GeneratedOtp
    {
        $this->validateSettings();
        $identifierHash = $this->hasher->identifierHash($this->identifier);
        $scopeKey = $this->hasher->scopeHash($identifierHash, $this->purpose);
        $lockWait = $this->config['locks']['generation_lock_wait_seconds'] ?? 5;
        if (!is_int($lockWait) || $lockWait < 1 || $lockWait > 300) {
            throw new InvalidArgumentException('Generation lock wait must be between 1 and 300 seconds.');
        }

        return $this->lockManager->synchronized('otp:generation:' . $scopeKey, $lockWait, function () use ($identifierHash, $scopeKey): GeneratedOtp {
            $retryAfter = $this->rateLimiter->consumeGenerationAllowance(
                $scopeKey,
                $this->settings['resend_cooldown'],
                $this->settings['max_generations'],
                $this->settings['generation_window'] * 60,
                $this->clock->now(),
            );

            if ($retryAfter !== null) {
                throw new OtpRateLimitException(
                    'OTP generation is temporarily rate limited.',
                    max(1, $retryAfter),
                );
            }

            $generated = $this->generator->generate($this->identifier, $this->purpose, $this->settings);
            $now = $this->clock->now();
            $record = new OtpRecord(
                id: null,
                identifierHash: $identifierHash,
                purpose: $this->purpose,
                tokenHash: $this->hasher->tokenHash($generated->token, $identifierHash, $this->purpose),
                expiresAt: $generated->expiresAt,
                attempts: 0,
                maxAttempts: $this->settings['max_attempts'],
                createdAt: $now,
                updatedAt: $now,
            );

            // Rate-limit reservations are committed before persistence. A DB
            // failure consumes the allowance conservatively rather than allowing
            // an attacker to use repeated failures to bypass the quota.
            $this->repository->transaction(fn () => $this->repository->save($record));

            return $generated;
        });
    }

    public function verify(string $token): bool
    {
        return $this->verifier->verify($this->identifier, $this->purpose, $token);
    }

    public function verifyAndConsume(string $token): bool
    {
        return $this->verifier->verifyAndConsume($this->identifier, $this->purpose, $token);
    }

    private function validateSettings(): void
    {
        $this->validateInt('validity', 1, 10080);
        $this->validateInt('length', 4, 128);
        $this->validateInt('max_attempts', 1, 65535);
        $this->validateInt('resend_cooldown', 0, 604800);
        $this->validateInt('max_generations', 0, 100000);
        $this->validateInt('generation_window', 1, 10080);

        $type = $this->settings['type'] ?? null;
        if (!in_array($type, ['numeric', 'alphanumeric'], true)) {
            throw new InvalidArgumentException('OTP type must be numeric or alphanumeric.');
        }

        // Reject invalid alphabets before reserving a generation allowance.
        if ($type === 'alphanumeric') {
            $alphabet = $this->settings['alphabet'] ?? null;
            if (!is_string($alphabet) || preg_match('/\A[A-Za-z0-9]{2,}\z/D', $alphabet) !== 1) {
                throw new InvalidArgumentException('Alphanumeric OTP alphabet must contain at least two ASCII letters or digits.');
            }
            if (strlen($alphabet) !== count(array_unique(str_split($alphabet)))) {
                throw new InvalidArgumentException('OTP alphabet must not contain duplicate characters.');
            }
        }
    }

    private function validateInt(string $key, int $minimum, int $maximum): void
    {
        $value = $this->settings[$key] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("OTP setting [{$key}] must be an integer between {$minimum} and {$maximum}.");
        }
    }
}
