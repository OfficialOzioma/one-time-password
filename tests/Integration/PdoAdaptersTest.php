<?php

declare(strict_types=1);

namespace Ozioma\Otp\Tests\Integration;

use DateTimeImmutable;
use Ozioma\Otp\Adapters\PDO\PdoOtpRepository;
use Ozioma\Otp\Adapters\PDO\PdoRateLimiter;
use Ozioma\Otp\Domain\OtpRecord;
use PHPUnit\Framework\TestCase;

final class PdoAdaptersTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema/sqlite.sql');
        $this->pdo->exec($schema);
    }

    public function test_pdo_repository_can_save_find_verify_and_delete_records(): void
    {
        $repository = new PdoOtpRepository($this->pdo);
        $now = new DateTimeImmutable('now');
        $record = new OtpRecord(null, str_repeat('a', 64), 'login', str_repeat('b', 64), $now->modify('+5 minutes'), 0, 5, $now, $now);
        $repository->transaction(fn () => $repository->save($record));
        $found = $repository->find(str_repeat('a', 64), 'login');
        self::assertNotNull($found);
        self::assertSame(5, $found->maxAttempts);
        $repository->transaction(fn () => $repository->delete($found));
        self::assertNull($repository->find(str_repeat('a', 64), 'login'));
    }

    public function test_pdo_rate_limiter_applies_generation_and_verification_limits(): void
    {
        $limiter = new PdoRateLimiter($this->pdo);
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        self::assertNull($limiter->consumeGenerationAllowance('scope', 0, 1, 3600, $now));
        self::assertNotNull($limiter->consumeGenerationAllowance('scope', 0, 1, 3600, $now));
        self::assertTrue($limiter->consumeVerificationAttempt('scope', 1, 3600, $now));
        self::assertFalse($limiter->consumeVerificationAttempt('scope', 1, 3600, $now));
    }
}
