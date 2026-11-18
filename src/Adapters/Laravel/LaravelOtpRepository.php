<?php

declare(strict_types=1);

namespace Ozioma\Otp\Adapters\Laravel;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Ozioma\Otp\Contracts\OtpRepositoryInterface;
use Ozioma\Otp\Domain\OtpRecord;

final class LaravelOtpRepository implements OtpRepositoryInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'otps',
    ) {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $this->table) !== 1) {
            throw new \InvalidArgumentException('Invalid OTP table identifier.');
        }
    }

    public function transaction(callable $callback): mixed
    {
        return $this->connection->transaction($callback, 3);
    }

    public function find(string $identifierHash, string $purpose, bool $forUpdate = false): ?OtpRecord
    {
        $query = $this->connection->table($this->table)
            ->where('identifier_hash', $identifierHash)
            ->where('purpose', $purpose);
        if ($forUpdate) { $query->lockForUpdate(); }
        $row = $query->first();
        return $row === null ? null : $this->hydrate((array) $row);
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
        $this->connection->table($this->table)->upsert(
            [$values],
            ['identifier_hash', 'purpose'],
            ['token_hash', 'expires_at', 'attempts', 'max_attempts', 'updated_at'],
        );
    }

    public function delete(OtpRecord $record): void
    {
        if ($record->id !== null) {
            $this->connection->table($this->table)->where('id', $record->id)->delete();
        }
    }

    public function pruneExpired(DateTimeImmutable $now, int $limit = 1000): int
    {
        if ($limit < 1 || $limit > 100000) {
            throw new \InvalidArgumentException('Prune limit must be between 1 and 100000.');
        }
        $cutoff = $now->getTimestamp();
        $ids = $this->connection->table($this->table)
            ->where('expires_at', '<=', $cutoff)
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        // Recheck expiry so a concurrently renewed record is not removed.
        return $this->connection->table($this->table)
            ->whereIn('id', $ids)
            ->where('expires_at', '<=', $cutoff)
            ->delete();
    }

    private function hydrate(array $row): OtpRecord
    {
        $utc = new \DateTimeZone('UTC');
        return new OtpRecord(
            id: (int) $row['id'],
            identifierHash: (string) $row['identifier_hash'],
            purpose: (string) $row['purpose'],
            tokenHash: (string) $row['token_hash'],
            expiresAt: (new DateTimeImmutable('@' . (int) $row['expires_at']))->setTimezone($utc),
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            createdAt: (new DateTimeImmutable('@' . (int) $row['created_at']))->setTimezone($utc),
            updatedAt: (new DateTimeImmutable('@' . (int) $row['updated_at']))->setTimezone($utc),
        );
    }
}
