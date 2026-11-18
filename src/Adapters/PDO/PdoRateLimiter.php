<?php

declare(strict_types=1);

namespace Ozioma\Otp\Adapters\PDO;

use DateTimeImmutable;
use PDO;
use PDOException;
use Ozioma\Otp\Contracts\RateLimiterInterface;
use RuntimeException;

final class PdoRateLimiter implements RateLimiterInterface
{
    private readonly string $driver;
    private int $transactionDepth = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'otp_rate_limits',
    ) {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $this->table) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL table identifier.');
        }
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Ozioma OTP supports PDO drivers: mysql, pgsql and sqlite.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function consumeGenerationAllowance(
        string $scopeKey,
        int $cooldownSeconds,
        int $maximumGenerations,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): ?int {
        if ($cooldownSeconds < 0 || $maximumGenerations < 0 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Invalid generation rate-limit settings.');
        }
        if ($cooldownSeconds === 0 && $maximumGenerations === 0) {
            return null;
        }

        return $this->transaction(function () use ($scopeKey, $cooldownSeconds, $maximumGenerations, $windowSeconds, $now): ?int {
            $timestamp = $now->getTimestamp();
            $cooldownKey = hash('sha256', 'cooldown:' . $scopeKey);
            $generationKey = hash('sha256', 'generation:' . $scopeKey);

            $keys = [];
            if ($cooldownSeconds > 0) { $keys[] = $cooldownKey; }
            if ($maximumGenerations > 0) { $keys[] = $generationKey; }
            sort($keys, SORT_STRING);

            foreach ($keys as $key) { $this->ensureBucket($key); }
            $buckets = [];
            foreach ($keys as $key) { $buckets[$key] = $this->getBucket($key); }

            if ($cooldownSeconds > 0) {
                $bucket = $buckets[$cooldownKey];
                if ($bucket !== null && $bucket['expires_at'] > $timestamp) {
                    return max(1, $bucket['expires_at'] - $timestamp);
                }
            }

            if ($maximumGenerations > 0) {
                $bucket = $buckets[$generationKey];
                if ($bucket !== null && $bucket['expires_at'] > $timestamp && $bucket['attempts'] >= $maximumGenerations) {
                    return max(1, $bucket['expires_at'] - $timestamp);
                }
            }

            if ($cooldownSeconds > 0) {
                $this->writeBucket($cooldownKey, 1, $timestamp + $cooldownSeconds);
            }

            if ($maximumGenerations > 0) {
                $bucket = $buckets[$generationKey];
                $active = $bucket !== null && $bucket['expires_at'] > $timestamp;
                $this->writeBucket(
                    $generationKey,
                    $active ? $bucket['attempts'] + 1 : 1,
                    $active ? $bucket['expires_at'] : $timestamp + $windowSeconds,
                );
            }

            return null;
        });
    }

    public function consumeVerificationAttempt(
        string $scopeKey,
        int $maximumAttempts,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): bool {
        if ($maximumAttempts < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Invalid verification rate-limit settings.');
        }

        return $this->transaction(function () use ($scopeKey, $maximumAttempts, $windowSeconds, $now): bool {
            $key = hash('sha256', 'verification:' . $scopeKey);
            $timestamp = $now->getTimestamp();
            $this->ensureBucket($key);
            $bucket = $this->getBucket($key);
            $active = $bucket !== null && $bucket['expires_at'] > $timestamp;
            $attempts = $active ? min($bucket['attempts'] + 1, $maximumAttempts + 1) : 1;
            $expiresAt = $active ? $bucket['expires_at'] : $timestamp + $windowSeconds;
            $this->writeBucket($key, $attempts, $expiresAt);

            return $attempts <= $maximumAttempts;
        });
    }

    public function pruneExpired(DateTimeImmutable $now, int $limit = 1000): int
    {
        if ($limit < 1 || $limit > 100000) {
            throw new \InvalidArgumentException('Prune limit must be between 1 and 100000.');
        }

        if ($this->driver === 'mysql') {
            $sql = "DELETE FROM {$this->table} WHERE expires_at <= :now LIMIT {$limit}";
        } else {
            $sql = "DELETE FROM {$this->table} WHERE bucket_key IN (SELECT bucket_key FROM {$this->table} WHERE expires_at <= :now ORDER BY expires_at LIMIT {$limit})";
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['now' => $now->getTimestamp()]);
        return $statement->rowCount();
    }

    private function ensureBucket(string $key): void
    {
        if ($this->driver === 'mysql') {
            $sql = "INSERT IGNORE INTO {$this->table} (bucket_key, attempts, expires_at) VALUES (:key, 0, 0)";
        } else {
            $sql = "INSERT INTO {$this->table} (bucket_key, attempts, expires_at) VALUES (:key, 0, 0) ON CONFLICT (bucket_key) DO NOTHING";
        }
        $this->pdo->prepare($sql)->execute(['key' => $key]);
    }

    private function getBucket(string $key): ?array
    {
        $sql = "SELECT attempts, expires_at FROM {$this->table} WHERE bucket_key = :key";
        if (in_array($this->driver, ['mysql', 'pgsql'], true)) { $sql .= ' FOR UPDATE'; }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { return null; }
        return ['attempts' => (int) $row['attempts'], 'expires_at' => (int) $row['expires_at']];
    }

    private function writeBucket(string $key, int $attempts, int $expiresAt): void
    {
        $sql = "UPDATE {$this->table} SET attempts = :attempts, expires_at = :expires_at WHERE bucket_key = :key";
        $this->pdo->prepare($sql)->execute([
            'attempts' => $attempts,
            'expires_at' => $expiresAt,
            'key' => $key,
        ]);
    }

    private function transaction(callable $callback): mixed
    {
        $savepoint = 'ozioma_rate_' . $this->transactionDepth;
        $ownsTransaction = $this->transactionDepth === 0 && !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            if ($this->driver === 'sqlite') { $this->pdo->exec('BEGIN IMMEDIATE'); }
            else { $this->pdo->beginTransaction(); }
        } else {
            // Respect a transaction opened by the host application.
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }
        $this->transactionDepth++;

        try {
            $result = $callback();
            if ($ownsTransaction) {
                if ($this->driver === 'sqlite') { $this->pdo->exec('COMMIT'); }
                else { $this->pdo->commit(); }
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            $this->transactionDepth--;
            return $result;
        } catch (\Throwable $exception) {
            $this->transactionDepth--;
            try {
                if ($ownsTransaction) {
                    if ($this->driver === 'sqlite') { $this->pdo->exec('ROLLBACK'); }
                    elseif ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            } catch (PDOException) {
                // Preserve the original exception.
            }
            throw $exception;
        }
    }
}
