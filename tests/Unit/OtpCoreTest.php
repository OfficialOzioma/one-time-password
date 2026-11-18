<?php

declare(strict_types=1);

namespace Ozioma\Otp\Tests\Unit;

use InvalidArgumentException;
use Ozioma\Otp\Core\OtpGenerator;
use Ozioma\Otp\Core\OtpHasher;
use Ozioma\Otp\Core\OtpManager;
use Ozioma\Otp\Core\OtpVerifier;
use Ozioma\Otp\Domain\GeneratedOtp;
use Ozioma\Otp\Exceptions\OtpRateLimitException;
use Ozioma\Otp\Tests\Fixtures\FrozenClock;
use Ozioma\Otp\Tests\Fixtures\InMemoryLockManager;
use Ozioma\Otp\Tests\Fixtures\InMemoryOtpRepository;
use Ozioma\Otp\Tests\Fixtures\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;

final class OtpCoreTest extends TestCase
{
    private FrozenClock $clock;
    private InMemoryOtpRepository $repository;
    private InMemoryRateLimiter $limiter;
    private OtpManager $manager;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $this->repository = new InMemoryOtpRepository();
        $this->limiter = new InMemoryRateLimiter();
        $hasher = new OtpHasher(str_repeat('t', 64), str_repeat('i', 64));
        $generator = new OtpGenerator($this->clock);
        $verifier = new OtpVerifier($this->repository, $hasher, $this->limiter, $this->clock, [
            'verification_rate_limit' => [
                'enabled' => true,
                'max_attempts' => 20,
                'decay_seconds' => 3600,
                'purposes' => [],
            ],
        ]);
        $this->manager = new OtpManager(
            $this->repository,
            $generator,
            $hasher,
            $verifier,
            $this->limiter,
            new InMemoryLockManager(),
            $this->clock,
            [
                'defaults' => [
                    'validity' => 10,
                    'length' => 6,
                    'type' => 'numeric',
                    'alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
                    'max_attempts' => 5,
                    'resend_cooldown' => 60,
                    'max_generations' => 5,
                    'generation_window' => 60,
                ],
                'purposes' => [
                    'password_reset' => ['validity' => 5, 'max_attempts' => 3],
                ],
                'locks' => ['generation_lock_wait_seconds' => 30],
            ],
        );
    }

    public function test_generates_and_persists_numeric_token(): void
    {
        $otp = $this->manager->for('Person@Example.com', 'login')->resendAfter(0)->generate();
        self::assertInstanceOf(GeneratedOtp::class, $otp);
        self::assertSame('person@example.com', $otp->identifier);
        self::assertMatchesRegularExpression('/\A[0-9]{6}\z/', $otp->token);
        self::assertCount(1, $this->repository->records);
        self::assertStringNotContainsString($otp->token, json_encode($otp, JSON_THROW_ON_ERROR));
        try {
            serialize($otp);
            self::fail('GeneratedOtp must not be serializable.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
    }

    public function test_supports_alphanumeric_tokens_and_overrides(): void
    {
        $otp = $this->manager->for('alice@example.com', 'invite')
            ->validFor(30)->length(8)->alphanumeric('ABCDEF234567')->resendAfter(0)->generate();
        self::assertSame(30, $otp->validityMinutes);
        self::assertSame(1800, $otp->expiresInSeconds);
        self::assertMatchesRegularExpression('/\A[ABCDEF234567]{8}\z/', $otp->token);
    }

    public function test_purpose_defaults_are_applied_before_operation_overrides(): void
    {
        $otp = $this->manager->for('alice@example.com', 'password_reset')
            ->length(4)->resendAfter(0)->generate();
        self::assertSame(5, $otp->validityMinutes);
        self::assertSame(4, strlen($otp->token));
        $record = array_values($this->repository->records)[0];
        self::assertSame(3, $record->maxAttempts);
    }

    public function test_valid_otp_can_be_checked_without_consumption(): void
    {
        $otp = $this->manager->for('alice@example.com', 'login')->resendAfter(0)->generate();
        self::assertTrue($this->manager->for('alice@example.com', 'login')->verify($otp->token));
        self::assertCount(1, $this->repository->records);
        self::assertTrue($this->manager->for('alice@example.com', 'login')->verifyAndConsume($otp->token));
        self::assertCount(0, $this->repository->records);
    }

    public function test_consumed_token_cannot_be_reused(): void
    {
        $otp = $this->manager->for('alice@example.com', 'login')->resendAfter(0)->generate();
        self::assertTrue($this->manager->for('alice@example.com', 'login')->verifyAndConsume($otp->token));
        self::assertFalse($this->manager->for('alice@example.com', 'login')->verifyAndConsume($otp->token));
    }

    public function test_incorrect_token_increments_attempts_and_exhausts_token(): void
    {
        $otp = $this->manager->for('alice@example.com', 'login')->maxAttempts(2)->resendAfter(0)->generate();
        $wrong = $otp->token === '000000' ? '000001' : '000000';
        $pending = $this->manager->for('alice@example.com', 'login');
        self::assertFalse($pending->verify($wrong));
        self::assertFalse($pending->verify($wrong));
        self::assertFalse($pending->verify($otp->token));
        self::assertSame(2, array_values($this->repository->records)[0]->attempts);
    }

    public function test_purpose_and_identifier_are_bound_to_token(): void
    {
        $otp = $this->manager->for('alice@example.com', 'email_verification')->resendAfter(0)->generate();
        self::assertFalse($this->manager->for('alice@example.com', 'login')->verify($otp->token));
        self::assertFalse($this->manager->for('bob@example.com', 'email_verification')->verify($otp->token));
        self::assertTrue($this->manager->for('alice@example.com', 'email_verification')->verifyAndConsume($otp->token));
    }

    public function test_expired_token_is_rejected_and_removed(): void
    {
        $otp = $this->manager->for('alice@example.com', 'login')->validFor(1)->resendAfter(0)->generate();
        $this->clock->advance('+61 seconds');
        self::assertFalse($this->manager->for('alice@example.com', 'login')->verify($otp->token));
        self::assertCount(0, $this->repository->records);
    }

    public function test_generation_cooldown_and_quota_are_enforced(): void
    {
        $pending = $this->manager->for('alice@example.com', 'login')->resendAfter(30)->maxGenerations(2, 60);
        $pending->generate();
        try {
            $pending->generate();
            self::fail('Expected cooldown exception.');
        } catch (OtpRateLimitException $exception) {
            self::assertGreaterThan(0, $exception->retryAfterSeconds);
        }

        $this->clock->advance('+31 seconds');
        $pending->generate();
        $this->clock->advance('+31 seconds');
        try {
            $pending->generate();
            self::fail('Expected generation quota exception.');
        } catch (OtpRateLimitException) {
            self::assertTrue(true);
        }
    }

    public function test_invalid_configuration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager->for('alice@example.com', 'login')->length(3)->generate();
    }

    public function test_global_verification_limit_applies_to_successful_non_consuming_checks(): void
    {
        $clock = $this->clock;
        $hasher = new OtpHasher(str_repeat('x', 64), str_repeat('y', 64));
        $generator = new OtpGenerator($clock);
        $limiter = new InMemoryRateLimiter();
        $verifier = new OtpVerifier($this->repository, $hasher, $limiter, $clock, [
            'verification_rate_limit' => [
                'enabled' => true,
                'max_attempts' => 2,
                'decay_seconds' => 3600,
                'purposes' => [],
            ],
        ]);
        $manager = new OtpManager(
            $this->repository, $generator, $hasher, $verifier, $limiter,
            new InMemoryLockManager(), $clock,
            [
                'defaults' => ['validity' => 10, 'length' => 6, 'type' => 'numeric', 'alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 'max_attempts' => 5, 'resend_cooldown' => 0, 'max_generations' => 0, 'generation_window' => 60],
                'purposes' => [],
                'locks' => ['generation_lock_wait_seconds' => 30],
            ],
        );
        $otp = $manager->for('count@example.com', 'login')->generate();
        $pending = $manager->for('count@example.com', 'login');
        self::assertTrue($pending->verify($otp->token));
        self::assertTrue($pending->verify($otp->token));
        self::assertFalse($pending->verify($otp->token));
    }

    public function test_hmac_keys_must_be_independent_and_long_enough(): void
    {
        $this->expectException(\Ozioma\Otp\Exceptions\ConfigurationException::class);
        new OtpHasher(str_repeat('x', 32), str_repeat('x', 32));
    }

    public function test_otp_secret_data_is_not_exposed_by_serialization_or_debug_output(): void
    {
        $otp = $this->manager->for('private@example.com', 'login')->resendAfter(0)->generate();
        $json = json_encode($otp, JSON_THROW_ON_ERROR);
        $debug = var_export($otp, true);

        self::assertStringNotContainsString($otp->token, $json);
        self::assertStringNotContainsString($otp->identifier, $json);
        self::assertStringNotContainsString($otp->token, $debug);
        self::assertStringNotContainsString($otp->identifier, $debug);

        $this->expectException(\LogicException::class);
        serialize($otp);
    }

    public function test_empty_identifier_and_invalid_purpose_are_rejected(): void
    {
        try {
            $this->manager->for('', 'login');
            self::fail('Expected empty identifier to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->manager->for('alice@example.com', 'bad purpose');
    }
}
