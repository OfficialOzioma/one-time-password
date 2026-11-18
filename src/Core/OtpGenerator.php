<?php

declare(strict_types=1);

namespace Ozioma\Otp\Core;

use InvalidArgumentException;
use Ozioma\Otp\Contracts\ClockInterface;
use Ozioma\Otp\Domain\GeneratedOtp;

final class OtpGenerator
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function generate(string $identifier, string $purpose, array $settings): GeneratedOtp
    {
        $validity = $this->positiveInteger($settings, 'validity', 1, 10080);
        $length = $this->positiveInteger($settings, 'length', 4, 128);
        $type = $settings['type'] ?? null;

        if (!in_array($type, ['numeric', 'alphanumeric'], true)) {
            throw new InvalidArgumentException('OTP type must be numeric or alphanumeric.');
        }

        $token = $type === 'numeric'
            ? $this->numeric($length)
            : $this->alphanumeric($length, $settings['alphabet'] ?? null);

        $generatedAt = $this->clock->now();
        $expiresInSeconds = $validity * 60;

        return new GeneratedOtp(
            identifier: $identifier,
            purpose: $purpose,
            token: $token,
            generatedAt: $generatedAt,
            expiresAt: $generatedAt->modify('+' . $expiresInSeconds . ' seconds'),
            validityMinutes: $validity,
            expiresInSeconds: $expiresInSeconds,
        );
    }

    private function numeric(int $length): string
    {
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            $token .= (string) random_int(0, 9);
        }

        return $token;
    }

    private function alphanumeric(int $length, mixed $alphabet): string
    {
        if (!is_string($alphabet) || preg_match('/\A[A-Za-z0-9]{2,}\z/D', $alphabet) !== 1) {
            throw new InvalidArgumentException('Alphanumeric OTP alphabet must contain at least two ASCII letters or digits.');
        }

        $characters = str_split($alphabet);
        if (count($characters) !== count(array_unique($characters))) {
            throw new InvalidArgumentException('OTP alphabet must not contain duplicate characters.');
        }

        $last = count($characters) - 1;
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            $token .= $characters[random_int(0, $last)];
        }

        return $token;
    }

    private function positiveInteger(array $settings, string $key, int $minimum, int $maximum): int
    {
        $value = $settings[$key] ?? null;

        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("OTP setting [{$key}] must be an integer between {$minimum} and {$maximum}.");
        }

        return $value;
    }
}
