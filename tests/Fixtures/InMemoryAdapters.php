<?php

declare(strict_types=1);

namespace Ozioma\Otp\Tests\Fixtures;

use DateTimeImmutable;
use Ozioma\Otp\Contracts\ClockInterface;
use Ozioma\Otp\Contracts\LockManagerInterface;
use Ozioma\Otp\Contracts\OtpRepositoryInterface;
use Ozioma\Otp\Contracts\RateLimiterInterface;
use Ozioma\Otp\Domain\OtpRecord;

final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time) {}
    public function now(): DateTimeImmutable { return $this->time; }
    public function advance(string $modify): void { $this->time = $this->time->modify($modify); }
}

final class InMemoryOtpRepository implements OtpRepositoryInterface
{
    /** @var array<string, OtpRecord> */
    public array $records = [];
    private int $nextId = 1;

    public function transaction(callable $callback): mixed
    {
        $snapshot = unserialize(serialize($this->records));
        try { return $callback(); }
        catch (\Throwable $exception) { $this->records = $snapshot; throw $exception; }
    }

    public function find(string $identifierHash, string $purpose, bool $forUpdate = false): ?OtpRecord
    {
        return $this->records[$identifierHash . ':' . $purpose] ?? null;
    }

    public function save(OtpRecord $record): void
    {
        $id = $record->id ?? $this->nextId++;
        $copy = new OtpRecord($id, $record->identifierHash, $record->purpose, $record->tokenHash, $record->expiresAt, $record->attempts, $record->maxAttempts, $record->createdAt, $record->updatedAt);
        $this->records[$record->identifierHash . ':' . $record->purpose] = $copy;
    }

    public function delete(OtpRecord $record): void
    {
        unset($this->records[$record->identifierHash . ':' . $record->purpose]);
    }

    public function pruneExpired(DateTimeImmutable $now, int $limit = 1000): int
    {
        $deleted = 0;
        foreach ($this->records as $key => $record) {
            if ($record->expiresAt <= $now && $deleted < $limit) { unset($this->records[$key]); $deleted++; }
        }
        return $deleted;
    }
}

final class InMemoryRateLimiter implements RateLimiterInterface
{
    private array $buckets = [];

    public function consumeGenerationAllowance(string $scopeKey, int $cooldownSeconds, int $maximumGenerations, int $windowSeconds, DateTimeImmutable $now): ?int
    {
        $timestamp = $now->getTimestamp();
        $cooldownKey = 'cooldown:' . $scopeKey;
        $generationKey = 'generation:' . $scopeKey;
        $cooldown = $this->buckets[$cooldownKey] ?? null;
        $generation = $this->buckets[$generationKey] ?? null;
        if ($cooldownSeconds > 0 && $cooldown && $cooldown['expires_at'] > $timestamp) { return $cooldown['expires_at'] - $timestamp; }
        if ($maximumGenerations > 0 && $generation && $generation['expires_at'] > $timestamp && $generation['attempts'] >= $maximumGenerations) { return $generation['expires_at'] - $timestamp; }
        if ($cooldownSeconds > 0) { $this->buckets[$cooldownKey] = ['attempts' => 1, 'expires_at' => $timestamp + $cooldownSeconds]; }
        if ($maximumGenerations > 0) {
            $active = $generation && $generation['expires_at'] > $timestamp;
            $this->buckets[$generationKey] = ['attempts' => $active ? $generation['attempts'] + 1 : 1, 'expires_at' => $active ? $generation['expires_at'] : $timestamp + $windowSeconds];
        }
        return null;
    }

    public function consumeVerificationAttempt(string $scopeKey, int $maximumAttempts, int $windowSeconds, DateTimeImmutable $now): bool
    {
        $key = 'verify:' . $scopeKey;
        $timestamp = $now->getTimestamp();
        $bucket = $this->buckets[$key] ?? null;
        $active = $bucket && $bucket['expires_at'] > $timestamp;
        $attempts = $active ? min($bucket['attempts'] + 1, $maximumAttempts + 1) : 1;
        $this->buckets[$key] = ['attempts' => $attempts, 'expires_at' => $active ? $bucket['expires_at'] : $timestamp + $windowSeconds];
        return $attempts <= $maximumAttempts;
    }

    public function pruneExpired(DateTimeImmutable $now, int $limit = 1000): int { return 0; }
}

final class InMemoryLockManager implements LockManagerInterface
{
    public function synchronized(string $key, int $waitSeconds, callable $callback): mixed { return $callback(); }
}
