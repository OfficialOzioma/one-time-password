<?php

declare(strict_types=1);

namespace Ozioma\Otp\Core;

use InvalidArgumentException;
use Ozioma\Otp\Contracts\ClockInterface;
use Ozioma\Otp\Contracts\LockManagerInterface;
use Ozioma\Otp\Contracts\OtpRepositoryInterface;
use Ozioma\Otp\Contracts\RateLimiterInterface;

final class OtpManager
{
    public function __construct(
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

    public function for(string $identifier, string $purpose): PendingOtp
    {
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/D', $purpose) !== 1) {
            throw new InvalidArgumentException('OTP purpose must be 1-64 letters, numbers, dots, underscores or hyphens.');
        }

        $identifier = $this->hasher->normalizeIdentifier($identifier);
        $defaults = $this->config['defaults'] ?? [];
        $purposeSettings = $this->config['purposes'][$purpose] ?? [];
        $settings = array_replace($defaults, $purposeSettings);

        return new PendingOtp(
            identifier: $identifier,
            purpose: $purpose,
            settings: $settings,
            repository: $this->repository,
            generator: $this->generator,
            hasher: $this->hasher,
            verifier: $this->verifier,
            rateLimiter: $this->rateLimiter,
            lockManager: $this->lockManager,
            clock: $this->clock,
            config: $this->config,
        );
    }
}
