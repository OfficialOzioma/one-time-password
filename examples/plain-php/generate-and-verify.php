<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ozioma\Otp\Adapters\Filesystem\FileLockManager;
use Ozioma\Otp\Adapters\PDO\PdoOtpRepository;
use Ozioma\Otp\Adapters\PDO\PdoRateLimiter;
use Ozioma\Otp\Core\OtpGenerator;
use Ozioma\Otp\Core\OtpHasher;
use Ozioma\Otp\Core\OtpManager;
use Ozioma\Otp\Core\OtpVerifier;
use Ozioma\Otp\Core\SystemClock;

$pdo = new PDO(
    getenv('DATABASE_DSN') ?: 'sqlite:' . dirname(__DIR__, 2) . '/database.sqlite',
    getenv('DATABASE_USER') ?: null,
    getenv('DATABASE_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$config = require dirname(__DIR__, 2) . '/config/otp-plain.php';
$config['hash_keys']['token'] = getenv('OTP_HASH_KEY') ?: '';
$config['hash_keys']['identifier'] = getenv('OTP_IDENTIFIER_HASH_KEY') ?: '';

$clock = new SystemClock();
$hasher = new OtpHasher($config['hash_keys']['token'], $config['hash_keys']['identifier']);
$repository = new PdoOtpRepository($pdo, $config['table']);
$rateLimiter = new PdoRateLimiter($pdo, $config['rate_limit_table']);
$generator = new OtpGenerator($clock);
$verifier = new OtpVerifier($repository, $hasher, $rateLimiter, $clock, $config);
$locks = new FileLockManager(dirname(__DIR__, 2) . '/var/otp-locks');
$otp = new OtpManager($repository, $generator, $hasher, $verifier, $rateLimiter, $locks, $clock, $config);

$generated = $otp->for('person@example.com', 'login')->generate();
echo "Deliver this code securely: {$generated->token}\n";

$submitted = $argv[1] ?? '';
if ($submitted !== '') {
    echo $otp->for('person@example.com', 'login')->verifyAndConsume($submitted)
        ? "Verified\n"
        : "Invalid or expired code\n";
}
