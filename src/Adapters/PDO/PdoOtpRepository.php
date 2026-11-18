<?php

declare(strict_types=1);

namespace Ozioma\Otp\Adapters\PDO;

use DateTimeImmutable;
use PDO;
use PDOException;
use Ozioma\Otp\Contracts\OtpRepositoryInterface;
use Ozioma\Otp\Domain\OtpRecord;
use RuntimeException;

final class PdoOtpRepository implements OtpRepositoryInterface
{
    private readonly string $driver;
    private int $transactionDepth = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'otps',
    ) {
        $this->assertIdentifier($this->table);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Ozioma OTP supports PDO drivers: mysql, pgsql and sqlite.');
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function transaction(callable $callback): mixed
    {
        $savepoint = 'ozioma_otp_' . $this->transactionDepth;
        $ownsTransaction = $this->transactionDepth === 0 && !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
        } else {
            // Respect transactions opened by the host application.
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        $this->transactionDepth++;

        try {
            $result = $callback();

            if ($ownsTransaction) {
                $this->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            $this->transactionDepth--;
            return $result;
        } catch (\Throwable $exception) {
            $this->transactionDepth--;
            if ($ownsTransaction) {
                $this->rollBack();
            } else {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (PDOException) {
                    // Preserve the original exception if rollback also fails.
                }
            }
            throw $exception;
        }
    }

    public function find(string $identifierHash, string $purpose, bool $forUpdate = false): ?OtpRecord
    {
        $sql = "SELECT id, identifier_hash, purpose, token_hash, expires_at, attempts, max_attempts, created_at, updated_at FROM {$this->table} WHERE identifier_hash = :identifier_hash AND purpose = :purpose";
        if ($forUpdate && in_array($this->driver, ['mysql', 'pgsql'], true)) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'identifier_hash' => $identifierHash,
            'purpose' => $purpose,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(OtpRecord $record): void
    {
        $values = [
            'identifier_hash' => $record->identifierHash,
            'purpose' => $record->purpose,
            'token_hash' => $record->tokenHash,
            'expires_at' => $record->expiresAt->getTimestamp(),
            'attempts' => $record->attempts,
            'max_attempts' => $record->maxAttempts,
            'created_at' => $record->createdAt->getTimestamp(),
            'updated_at' => $record->updatedAt->getTimestamp(),
        ];

        if ($this->driver === 'mysql') {
            $sql = "INSERT INTO {$this->table} (identifier_hash, purpose, token_hash, expires_at, attempts, max_attempts, created_at, updated_at) VALUES (:identifier_hash, :purpose, :token_hash, :expires_at, :attempts, :max_attempts, :created_at, :updated_at) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), attempts = VALUES(attempts), max_attempts = VALUES(max_attempts), updated_at = VALUES(updated_at)";
        } else {
            $sql = "INSERT INTO {$this->table} (identifier_hash, purpose, token_hash, expires_at, attempts, max_attempts, created_at, updated_at) VALUES (:identifier_hash, :purpose, :token_hash, :expires_at, :attempts, :max_attempts, :created_at, :updated_at) ON CONFLICT (identifier_hash, purpose) DO UPDATE SET token_hash = EXCLUDED.token_hash, expires_at = EXCLUDED.expires_at, attempts = EXCLUDED.attempts, max_attempts = EXCLUDED.max_attempts, updated_at = EXCLUDED.updated_at";
        }

        $this->pdo->prepare($sql)->execute($values);
    }

    public function delete(OtpRecord $record): void
    {
        if ($record->id === null) {
            return;
        }

        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id");
        $statement->execute(['id' => $record->id]);
    }

    public function pruneExpired(DateTimeImmutable $now, int $limit = 1000): int
    {
        if ($limit < 1 || $limit > 100000) {
            throw new \InvalidArgumentException('Prune limit must be between 1 and 100000.');
        }

        // Bounded deletion avoids holding an unbounded cleanup transaction.
        if ($this->driver === 'mysql') {
            $sql = "DELETE FROM {$this->table} WHERE expires_at <= :now LIMIT {$limit}";
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['now' => $now->getTimestamp()]);
            return $statement->rowCount();
        }

        $sql = "DELETE FROM {$this->table} WHERE id IN (SELECT id FROM {$this->table} WHERE expires_at <= :now ORDER BY expires_at LIMIT {$limit})";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['now' => $now->getTimestamp()]);
        return $statement->rowCount();
    }

    private function hydrate(array $row): OtpRecord
    {
        return new OtpRecord(
            id: (int) $row['id'],
            identifierHash: (string) $row['identifier_hash'],
            purpose: (string) $row['purpose'],
            tokenHash: (string) $row['token_hash'],
            expiresAt: (new DateTimeImmutable('@' . (int) $row['expires_at']))->setTimezone(new \DateTimeZone('UTC')),
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            createdAt: (new DateTimeImmutable('@' . (int) $row['created_at']))->setTimezone(new \DateTimeZone('UTC')),
            updatedAt: (new DateTimeImmutable('@' . (int) $row['updated_at']))->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $identifier) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL table identifier.');
        }
    }

    private function commit(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('COMMIT');
        } else {
            $this->pdo->commit();
        }
    }

    private function rollBack(): void
    {
        try {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (PDOException) {
            // Preserve the original exception from the transaction callback.
        }
    }
}
