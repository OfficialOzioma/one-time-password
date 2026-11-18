<?php

declare(strict_types=1);

namespace Ozioma\Otp\Adapters\Laravel;

use DateTimeImmutable;
use RuntimeException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Ozioma\Otp\Contracts\LockManagerInterface;
use Ozioma\Otp\Contracts\RateLimiterInterface;

final class LaravelRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly LockManagerInterface $locks,
        private readonly ?string $store = null,
    ) {
    }

    public function consumeGenerationAllowance(string $scopeKey, int $cooldownSeconds, int $maximumGenerations, int $windowSeconds, DateTimeImmutable $now): ?int
    {
        $store = $this->cache->store($this->store);
        $base = 'ozioma-otp:limit:generation:' . hash('sha256', $scopeKey);

        return $this->locks->synchronized($base, 5, function () use ($store, $base, $cooldownSeconds, $maximumGenerations, $windowSeconds, $now): ?int {
            $timestamp = $now->getTimestamp();
            $cooldown = $store->get($base . ':cooldown');
            $generations = $store->get($base . ':generations');

            if ($cooldownSeconds > 0 && is_array($cooldown) && ($cooldown['expires_at'] ?? 0) > $timestamp) {
                return max(1, (int) $cooldown['expires_at'] - $timestamp);
            }
            if ($maximumGenerations > 0 && is_array($generations)
                && ($generations['expires_at'] ?? 0) > $timestamp
                && (int) ($generations['attempts'] ?? 0) >= $maximumGenerations) {
                return max(1, (int) $generations['expires_at'] - $timestamp);
            }

            if ($cooldownSeconds > 0) {
                $stored = $store->put($base . ':cooldown', [
                    'attempts' => 1,
                    'expires_at' => $timestamp + $cooldownSeconds,
                ], $cooldownSeconds);
                if ($stored !== true) {
                    throw new RuntimeException('Could not persist the OTP generation cooldown.');
                }
            }
            if ($maximumGenerations > 0) {
                $active = is_array($generations) && ($generations['expires_at'] ?? 0) > $timestamp;
                $expiresAt = $active ? (int) $generations['expires_at'] : $timestamp + $windowSeconds;
                $attempts = $active ? (int) $generations['attempts'] + 1 : 1;
                $stored = $store->put($base . ':generations', [
                    'attempts' => $attempts,
                    'expires_at' => $expiresAt,
                ], max(1, $expiresAt - $timestamp));
                if ($stored !== true) {
                    throw new RuntimeException('Could not persist the OTP generation quota.');
                }
            }
            return null;
        });
    }

    public function consumeVerificationAttempt(string $scopeKey, int $maximumAttempts, int $windowSeconds, DateTimeImmutable $now): bool
    {
        $store = $this->cache->store($this->store);
        $key = 'ozioma-otp:limit:verification:' . hash('sha256', $scopeKey);

        return $this->locks->synchronized($key, 5, function () use ($store, $key, $maximumAttempts, $windowSeconds, $now): bool {
            $timestamp = $now->getTimestamp();
            $bucket = $store->get($key);
            $active = is_array($bucket) && ($bucket['expires_at'] ?? 0) > $timestamp;
            $attempts = $active ? min((int) ($bucket['attempts'] ?? 0) + 1, $maximumAttempts + 1) : 1;
            $expiresAt = $active ? (int) $bucket['expires_at'] : $timestamp + $windowSeconds;
            $stored = $store->put($key, [
                'attempts' => $attempts,
                'expires_at' => $expiresAt,
            ], max(1, $expiresAt - $timestamp));
            if ($stored !== true) {
                throw new RuntimeException('Could not persist the OTP verification rate limit.');
            }

            return $attempts <= $maximumAttempts;
        });
    }

    public function pruneExpired(DateTimeImmutable $now, int $limit = 1000): int
    {
        // Cache entries expire automatically according to their TTL.
        return 0;
    }
}
