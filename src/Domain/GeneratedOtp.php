<?php

declare(strict_types=1);

namespace Ozioma\Otp\Domain;

use DateTimeImmutable;
use JsonSerializable;
use LogicException;
use WeakMap;

/**
 * @property-read string $identifier
 * @property-read string $token Secret token, intended only for trusted delivery code.
 */
final class GeneratedOtp implements JsonSerializable
{
    /** @var WeakMap<self, array{identifier: string, token: string}>|null */
    private static ?WeakMap $secrets = null;

    public function __construct(
        string $identifier,
        public readonly string $purpose,
        string $token,
        public readonly DateTimeImmutable $generatedAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly int $validityMinutes,
        public readonly int $expiresInSeconds,
    ) {
        self::$secrets ??= new WeakMap();
        self::$secrets[$this] = [
            'identifier' => $identifier,
            'token' => $token,
        ];
    }

    /** Expose sensitive values explicitly to trusted application code. */
    public function __get(string $name): mixed
    {
        if (in_array($name, ['identifier', 'token'], true)) {
            return self::$secrets[$this][$name]
                ?? throw new LogicException('Generated OTP data is no longer available.');
        }

        throw new LogicException("Undefined generated OTP property [{$name}].");
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['identifier', 'token'], true)
            && isset(self::$secrets[$this][$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        throw new LogicException('Generated OTP objects are immutable.');
    }

    public function __unset(string $name): void
    {
        throw new LogicException('Generated OTP objects are immutable.');
    }

    /** Never expose the identifier or token through JSON serialization. */
    public function jsonSerialize(): array
    {
        return [
            'purpose' => $this->purpose,
            'generatedAt' => $this->generatedAt->format(DATE_ATOM),
            'expiresAt' => $this->expiresAt->format(DATE_ATOM),
            'validityMinutes' => $this->validityMinutes,
            'expiresInSeconds' => $this->expiresInSeconds,
        ];
    }

    /** Redact the identifier and token when inspected with var_dump() / print_r(). */
    public function __debugInfo(): array
    {
        return [
            'identifier' => '[REDACTED]',
            'purpose' => $this->purpose,
            'token' => '[REDACTED]',
            'generatedAt' => $this->generatedAt,
            'expiresAt' => $this->expiresAt,
            'validityMinutes' => $this->validityMinutes,
            'expiresInSeconds' => $this->expiresInSeconds,
        ];
    }

    /** Prevent accidental sensitive data persistence through PHP serialization. */
    public function __serialize(): array
    {
        throw new LogicException('Generated OTP objects contain sensitive data and cannot be serialized.');
    }

    public function __clone()
    {
        throw new LogicException('Generated OTP objects cannot be cloned.');
    }
}
