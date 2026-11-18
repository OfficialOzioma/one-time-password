<?php

declare(strict_types=1);

namespace Ozioma\Otp\Contracts;

use DateTimeImmutable;
use Ozioma\Otp\Domain\OtpRecord;

interface OtpRepositoryInterface
{
    /** Execute the callback in a database transaction. */
    public function transaction(callable $callback): mixed;

    /** Must be called inside transaction() when row-level locking is required. */
    public function find(string $identifierHash, string $purpose, bool $forUpdate = false): ?OtpRecord;

    /** Insert or replace the active record for an identifier and purpose. */
    public function save(OtpRecord $record): void;

    public function delete(OtpRecord $record): void;

    public function pruneExpired(DateTimeImmutable $now, int $limit = 1000): int;
}
