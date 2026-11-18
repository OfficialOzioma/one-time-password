<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Ozioma\Otp\Adapters\Filesystem\FileLockManager;
use Ozioma\Otp\Core\OtpGenerator;
use Ozioma\Otp\Core\OtpHasher;
use Ozioma\Otp\Core\OtpManager;
use Ozioma\Otp\Core\OtpVerifier;
use Ozioma\Otp\Exceptions\OtpRateLimitException;
use Ozioma\Otp\Tests\Fixtures\FrozenClock;
use Ozioma\Otp\Tests\Fixtures\InMemoryLockManager;
use Ozioma\Otp\Tests\Fixtures\InMemoryOtpRepository;
use Ozioma\Otp\Tests\Fixtures\InMemoryRateLimiter;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
};

$clock = new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
$repository = new InMemoryOtpRepository();
$limiter = new InMemoryRateLimiter();
$hasher = new OtpHasher(str_repeat('t', 64), str_repeat('i', 64));
$generator = new OtpGenerator($clock);
$verifier = new OtpVerifier($repository, $hasher, $limiter, $clock, [
    'verification_rate_limit' => ['enabled' => true, 'max_attempts' => 20, 'decay_seconds' => 3600, 'purposes' => []],
]);
$manager = new OtpManager($repository, $generator, $hasher, $verifier, $limiter, new InMemoryLockManager(), $clock, [
    'defaults' => ['validity' => 10, 'length' => 6, 'type' => 'numeric', 'alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 'max_attempts' => 5, 'resend_cooldown' => 60, 'max_generations' => 5, 'generation_window' => 60],
    'purposes' => ['password_reset' => ['validity' => 5, 'max_attempts' => 3]],
    'locks' => ['generation_lock_wait_seconds' => 30],
]);

$otp = $manager->for('Person@Example.com', 'login')->resendAfter(0)->generate();
$assert($otp->identifier === 'person@example.com', 'email normalization');
$assert(preg_match('/\A[0-9]{6}\z/', $otp->token) === 1, 'numeric token format');
$assert(count($repository->records) === 1, 'OTP persisted');
$assert(!str_contains(json_encode($otp, JSON_THROW_ON_ERROR), $otp->token), 'JSON serialization redacts token');
$assert($manager->for('person@example.com', 'login')->verify($otp->token), 'non-consuming verification passes');
$assert(count($repository->records) === 1, 'non-consuming verification keeps record');
$assert($manager->for('person@example.com', 'login')->verifyAndConsume($otp->token), 'consume succeeds');
$assert(count($repository->records) === 0, 'consumption removes record');
$assert(!$manager->for('person@example.com', 'login')->verifyAndConsume($otp->token), 'consumed OTP cannot be reused');


$serialized = json_encode($otp, JSON_THROW_ON_ERROR);
$assert(!str_contains($serialized, $otp->identifier), 'JSON serialization redacts identifier');
$assert(!str_contains(var_export($otp, true), $otp->token), 'var_export does not expose token');
$immutable = false;
try { $otp->token = '999999'; } catch (LogicException) { $immutable = true; }
$assert($immutable, 'generated object is immutable');
try { serialize($otp); $serializedBlocked = false; } catch (LogicException) { $serializedBlocked = true; }
$assert($serializedBlocked, 'generated object cannot be serialized');

$rateLimitOtp = $manager->for('verify-limit@example.com', 'login')->resendAfter(0)->generate();
$verificationPending = $manager->for('verify-limit@example.com', 'login');
for ($i = 0; $i < 20; $i++) {
    $assert($verificationPending->verify($rateLimitOtp->token), 'valid non-consuming verification within global limit');
}
$assert(!$verificationPending->verify($rateLimitOtp->token), 'global verification request limit enforced');

$alpha = $manager->for('alpha@example.com', 'invite')->validFor(30)->length(8)->alphanumeric('ABCDEF234567')->resendAfter(0)->generate();
$assert(strlen($alpha->token) === 8 && preg_match('/\A[ABCDEF234567]{8}\z/', $alpha->token) === 1, 'alphanumeric settings');
$assert($alpha->validityMinutes === 30 && $alpha->expiresInSeconds === 1800, 'validity override');

$wrongOtp = $manager->for('wrong@example.com', 'login')->maxAttempts(2)->resendAfter(0)->generate();
$wrong = $wrongOtp->token === '000000' ? '000001' : '000000';
$p = $manager->for('wrong@example.com', 'login');
$assert(!$p->verify($wrong), 'wrong token rejected');
$assert(!$p->verify($wrong), 'second wrong token rejected');
$assert(!$p->verify($wrongOtp->token), 'token rejected after max attempts');

$purposeOtp = $manager->for('purpose@example.com', 'email_verification')->resendAfter(0)->generate();
$assert(!$manager->for('purpose@example.com', 'login')->verify($purposeOtp->token), 'purpose isolation');
$assert(!$manager->for('another@example.com', 'email_verification')->verify($purposeOtp->token), 'identifier isolation');
$assert($manager->for('purpose@example.com', 'email_verification')->verifyAndConsume($purposeOtp->token), 'original purpose succeeds');

$expired = $manager->for('expired@example.com', 'login')->validFor(1)->resendAfter(0)->generate();
$clock->advance('+61 seconds');
$assert(!$manager->for('expired@example.com', 'login')->verify($expired->token), 'expired token rejected');

$rate = $manager->for('quota@example.com', 'login')->resendAfter(30)->maxGenerations(2, 60);
$rate->generate();
$throttled = false;
try { $rate->generate(); } catch (OtpRateLimitException) { $throttled = true; }
$assert($throttled, 'cooldown enforced');
$clock->advance('+31 seconds');
$rate->generate();
$clock->advance('+31 seconds');
$throttled = false;
try { $rate->generate(); } catch (OtpRateLimitException) { $throttled = true; }
$assert($throttled, 'generation quota enforced within fixed window');

$badAlphabet = false;
try { $manager->for('bad@example.com', 'invite')->alphanumeric('AABC')->generate(); } catch (InvalidArgumentException) { $badAlphabet = true; }
$assert($badAlphabet, 'duplicate alphabet rejected');

$invalidPurpose = false;
try { $manager->for('uppercase@example.com', 'LOGIN'); } catch (InvalidArgumentException) { $invalidPurpose = true; }
$assert($invalidPurpose, 'purpose names must be lowercase');



// Invalid per-operation configuration must not consume a generation quota.
$invalidAlphabetRejected = false;
try {
    $manager->for('alphabet-quota@example.com', 'invite')
        ->alphanumeric('AABC')
        ->resendAfter(0)
        ->maxGenerations(1, 60)
        ->generate();
} catch (InvalidArgumentException) {
    $invalidAlphabetRejected = true;
}
$assert($invalidAlphabetRejected, 'invalid alphabet is rejected before generation');
$validAfterInvalid = $manager->for('alphabet-quota@example.com', 'invite')
    ->alphanumeric('ABC123')
    ->resendAfter(0)
    ->maxGenerations(1, 60)
    ->generate();
$assert(strlen($validAfterInvalid->token) === 6, 'invalid configuration did not consume generation quota');

// Exercise the real single-host lock adapter and its root-directory guard.
$tempLockDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ozioma-otp-' . bin2hex(random_bytes(8));
$fileLocks = new FileLockManager($tempLockDirectory);
$lockResult = $fileLocks->synchronized('smoke-lock', 1, static fn (): string => 'locked');
$assert($lockResult === 'locked', 'filesystem lock executes callback');
foreach (glob($tempLockDirectory . DIRECTORY_SEPARATOR . '*.lock') ?: [] as $lockFile) {
    unlink($lockFile);
}
rmdir($tempLockDirectory);
$rootRejected = false;
try {
    new FileLockManager(DIRECTORY_SEPARATOR);
} catch (RuntimeException) {
    $rootRejected = true;
}
$assert($rootRejected, 'filesystem root cannot be used as lock directory');

fwrite(STDOUT, "PASS: {$assertions} core assertions\n");
