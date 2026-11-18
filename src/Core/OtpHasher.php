<?php

declare(strict_types=1);

namespace Ozioma\Otp\Core;

use InvalidArgumentException;
use Ozioma\Otp\Exceptions\ConfigurationException;

final class OtpHasher
{
    public function __construct(
        private readonly string $tokenKey,
        private readonly string $identifierKey,
    ) {
        if (strlen($this->tokenKey) < 32 || strlen($this->identifierKey) < 32) {
            throw new ConfigurationException('OTP HMAC keys must each contain at least 32 bytes.');
        }
        if (hash_equals($this->tokenKey, $this->identifierKey)) {
            throw new ConfigurationException('OTP token and identifier HMAC keys must be different.');
        }
    }

    public function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw new InvalidArgumentException('OTP identifier cannot be empty.');
        }
        if (strlen($identifier) > 1024) {
            throw new InvalidArgumentException('OTP identifier cannot exceed 1024 bytes.');
        }

        // Email local-parts are technically case-sensitive, but most consumer
        // applications treat them case-insensitively. Keep this normalization
        // explicit and consistent for generation and verification.
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            return strtolower($identifier);
        }

        return $identifier;
    }

    public function identifierHash(string $identifier): string
    {
        return hash_hmac('sha256', $this->normalizeIdentifier($identifier), $this->identifierKey);
    }

    public function tokenHash(string $token, string $identifierHash, string $purpose): string
    {
        $payload = $purpose . "\0" . $identifierHash . "\0" . $token;

        return hash_hmac('sha256', $payload, $this->tokenKey);
    }

    /** Return a non-secret stable scope key suitable for cache/database rate-limit keys. */
    public function scopeHash(string $identifierHash, string $purpose): string
    {
        return hash('sha256', $identifierHash . "\0" . $purpose);
    }
}
