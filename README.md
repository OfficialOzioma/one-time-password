# Ozioma OTP

A reusable, framework-independent one-time password (OTP) engine for PHP applications, with an optional Laravel integration.

- **Composer:** `ozioma/otp`
- **PHP namespace:** `Ozioma\Otp`
- **PHP:** 8.2 or newer
- **Laravel integration:** designed for Laravel 11, 12 and 13
- **License:** MIT

The core handles token generation, secure hashing, expiry, purpose isolation, attempt tracking and rate limiting. It does not send email or SMS. Your application controls delivery and decides what a successfully verified OTP authorizes.

> **Release status:** Initial release candidate. The framework-independent core was exercised with an in-memory test harness and PHP syntax checks. PDO integration tests require an installed PDO driver; Laravel integration tests require Composer dependencies and a Laravel/Testbench environment. These environments were not available during this build, so run the full test matrix against your target database and Laravel version before production use.

## Features

- Numeric and alphanumeric OTPs generated using `random_int()`.
- Global defaults, purpose-specific settings and per-operation overrides.
- Purpose and identifier binding: a login code cannot be used for password reset or another identifier.
- Expiration and per-OTP failed-attempt limits.
- Resend cooldown and generation quota.
- Separate verification request rate limiting across OTP generations.
- HMAC-SHA256 token and identifier hashing; plaintext OTPs are never persisted.
- One active OTP per identifier and purpose.
- Explicit non-consuming `verify()` and one-time `verifyAndConsume()` operations.
- Storage, clock, rate-limit and lock contracts for alternate adapters.
- PDO adapters for MySQL, PostgreSQL and SQLite.
- Filesystem lock adapter for single-host plain PHP deployments.
- Optional Laravel service provider, facade, validation rule, migration and Artisan cleanup command.

## Installation

```bash
composer require ozioma/otp
```

Laravel's package discovery automatically registers the optional service provider when Laravel is present. Plain PHP applications use the core classes directly.

Until the package is published to Packagist, install it from a local path repository or VCS repository. See [Installation](docs/INSTALLATION.md).

## Laravel

### Setup

Publish the configuration and migration:

```bash
php artisan vendor:publish --tag=otp-config
php artisan vendor:publish --tag=otp-migrations
php artisan migrate
```

Generate two independent secrets:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Add separate values to `.env`:

```dotenv
OTP_HASH_KEY=first_64_character_hex_secret
OTP_IDENTIFIER_HASH_KEY=second_64_character_hex_secret
```

Do not commit these secrets. Keep them the same across all application servers. Rotating either key makes existing OTP records unverifiable; normally they should be allowed to expire before a planned key rotation.

The Laravel adapter uses the application's database connection and cache store. Use a shared cache backend that supports atomic locks (such as Redis or a correctly configured database cache) across all application instances. Set the lock lease (`locks.lease_seconds`) longer than the expected maximum duration of a generation operation. Set `OTP_CACHE_STORE` to choose a named store and `OTP_DB_CONNECTION` to choose a database connection.

### Generate an OTP

```php
use Ozioma\Otp\Laravel\Facades\Otp;

$otp = Otp::for('person@example.com', 'email_verification')
    ->validFor(10)             // minutes
    ->length(6)
    ->numeric()
    ->maxAttempts(5)
    ->resendAfter(60)          // seconds
    ->maxGenerations(5, 60)    // count, window in minutes
    ->generate();

// Deliver $otp->token using your application's email/SMS notification.
```

Alphanumeric tokens:

```php
$otp = Otp::for('person@example.com', 'invite')
    ->length(8)
    ->alphanumeric('ABCDEFGHJKLMNPQRSTUVWXYZ23456789')
    ->generate();
```

### Verify an OTP

```php
$isValid = Otp::for('person@example.com', 'email_verification')
    ->verifyAndConsume($submittedToken);
```

`verifyAndConsume()` removes a valid OTP. `verify()` checks a valid OTP without consuming it. Both operations count against the configured verification rate limit; incorrect codes also increment the per-OTP attempt counter.

### Laravel validation rule

```php
use Ozioma\Otp\Laravel\Rules\OtpRule;

$request->validate([
    'otp' => [
        'required',
        'string',
        new OtpRule(
            identifier: $request->user()->email,
            purpose: 'email_verification',
            message: 'The code is invalid or has expired.',
        ),
    ],
]);
```

The rule consumes a valid OTP by default. Use `consumeOnSuccess: false` if validation should only check the code; after all other business checks pass, explicitly call `verifyAndConsume()`. Validation rules that consume tokens have a side effect, so place the OTP rule after other checks that may fail, or use non-consuming validation followed by explicit consumption.

### Configuration

The Laravel integration publishes a conventional Laravel configuration file to `config/otp.php`. Like other Laravel configuration files, it is a PHP file that returns an array; it is not a PHP class. It uses Laravel's `env()` helper for environment-backed values and is the only configuration file published by the Laravel service provider.

Plain PHP applications should use the separate `config/otp-plain.php` file, which reads environment variables with `getenv()` and does not depend on Laravel helpers.

Both files support global defaults, purpose-specific overrides, and per-operation fluent overrides. Precedence is:

1. Global defaults.
2. Purpose-specific settings.
3. Current operation's fluent settings.

Example purpose configuration:

```php
'purposes' => [
    'transaction_authorization' => [
        'validity' => 3,
        'length' => 8,
        'type' => 'numeric',
        'max_attempts' => 3,
    ],
],
```

### Cleanup schedule

The package registers `otp:prune-expired`:

```bash
php artisan otp:prune-expired --chunk=1000
```

Schedule it in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('otp:prune-expired --chunk=1000')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

`onOneServer()` requires a shared cache store that supports locks. Expiration is checked during verification even if the cleanup command has not run.

## Plain PHP

### 1. Create the database tables

Run the schema matching your PDO driver:

- `database/schema/mysql.sql`
- `database/schema/postgresql.sql`
- `database/schema/sqlite.sql`

The schema creates `otps` and `otp_rate_limits`. The PDO adapters require `ext-pdo` and the matching PDO driver. Use InnoDB for MySQL.

### 2. Instantiate the core

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ozioma\Otp\Adapters\Filesystem\FileLockManager;
use Ozioma\Otp\Adapters\PDO\PdoOtpRepository;
use Ozioma\Otp\Adapters\PDO\PdoRateLimiter;
use Ozioma\Otp\Core\OtpGenerator;
use Ozioma\Otp\Core\OtpHasher;
use Ozioma\Otp\Core\OtpManager;
use Ozioma\Otp\Core\OtpVerifier;
use Ozioma\Otp\Core\SystemClock;

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=app;charset=utf8mb4',
    'db_user',
    'db_password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$config = require __DIR__ . '/vendor/ozioma/otp/config/otp-plain.php';
$config['hash_keys']['token'] = getenv('OTP_HASH_KEY');
$config['hash_keys']['identifier'] = getenv('OTP_IDENTIFIER_HASH_KEY');

$clock = new SystemClock();
$hasher = new OtpHasher(
    (string) $config['hash_keys']['token'],
    (string) $config['hash_keys']['identifier'],
);
$repository = new PdoOtpRepository($pdo, $config['table']);
$rateLimiter = new PdoRateLimiter($pdo, $config['rate_limit_table']);
$generator = new OtpGenerator($clock);
$verifier = new OtpVerifier($repository, $hasher, $rateLimiter, $clock, $config);
$locks = new FileLockManager(__DIR__ . '/var/otp-locks');

$otp = new OtpManager(
    $repository,
    $generator,
    $hasher,
    $verifier,
    $rateLimiter,
    $locks,
    $clock,
    $config,
);

$generated = $otp->for('person@example.com', 'login')->generate();
// Deliver $generated->token using your mail or SMS implementation.

if ($otp->for('person@example.com', 'login')->verifyAndConsume($submittedToken)) {
    // Continue the login flow.
}
```

Keep the filesystem lock directory outside the public web root and writable only by the application user. `FileLockManager` coordinates processes on one host only. For multiple hosts, implement `LockManagerInterface` using a distributed lock service (for example Redis) and inject that implementation.

### 3. Cleanup from cron

Run this periodically from cron or your application's scheduler:

```php
$now = $clock->now();
$deletedOtpRows = $repository->pruneExpired($now, 1000);
$deletedRateLimitRows = $rateLimiter->pruneExpired($now, 1000);
```

## API reference

### Generate

```php
$generated = $manager->for($identifier, 'login')
    ->validFor(10)
    ->length(6)
    ->numeric()
    ->maxAttempts(5)
    ->resendAfter(60)
    ->maxGenerations(5, 60)
    ->generate();
```

`maxGenerations($count, $windowMinutes)` sets the number of generations allowed in a fixed time window. A value of `0` disables that quota. `resendAfter(0)` disables the cooldown.

Generated object properties:

```php
$generated->identifier;
$generated->purpose;
$generated->token;
$generated->generatedAt;       // DateTimeImmutable
$generated->expiresAt;         // DateTimeImmutable
$generated->validityMinutes;
$generated->expiresInSeconds;
```

The token is a secret. Do not log it, serialize it into an API response, or expose the generated object to an untrusted client. JSON serialization and debug output omit/redact the identifier and token, and PHP serialization is disabled. Direct access to `$generated->identifier` and `$generated->token` is intentionally available to trusted delivery code.

### Verify

```php
$manager->for($identifier, 'login')->verify($token);            // does not consume success
$manager->for($identifier, 'login')->verifyAndConsume($token);  // consumes success
```

Both count each verification request against the global identifier + purpose verification limit. Incorrect tokens increment the OTP record's attempt counter. When the maximum per-OTP attempts is reached, a new OTP must be generated (subject to generation limits).

## Configuration options

| Setting | Default | Meaning |
|---|---:|---|
| `validity` | 10 | Lifetime in minutes (1–10080) |
| `length` | 6 | Token length (4–128) |
| `type` | `numeric` | `numeric` or `alphanumeric` |
| `alphabet` | Confusable-safe alphabet | Allowed alphanumeric characters |
| `max_attempts` | 5 | Incorrect attempts per generated OTP |
| `resend_cooldown` | 60 | Minimum seconds between generations; 0 disables |
| `max_generations` | 5 | Generations per window; 0 disables |
| `generation_window` | 60 | Generation quota window in minutes |
| `verification_rate_limit.enabled` | true | Enables request limit across generations |
| `verification_rate_limit.max_attempts` | 20 | Verification requests per identifier + purpose window |
| `verification_rate_limit.decay_seconds` | 3600 | Verification limit window in seconds |
| `locks.generation_lock_wait_seconds` | 5 | Maximum time to wait to acquire a generation lock |
| `locks.lease_seconds` | 60 | Laravel cache-lock lease duration; must exceed expected operation duration |

There is intentionally no `useSameToken` option. The package stores only keyed hashes and cannot recover an old plaintext code. Resending generates a new code and replaces the previous record.

## Database and concurrency notes

- PDO adapters support MySQL, PostgreSQL and SQLite. The matching PDO driver must be installed.
- Use InnoDB for MySQL. The PDO adapters use database transactions and row locks for verification.
- `PdoRateLimiter` stores atomic rate-limit buckets in `otp_rate_limits`. Pass the same PDO connection to both PDO adapters.
- `FileLockManager` is single-host. A multi-host plain PHP deployment must inject a distributed lock manager. Its wait setting limits lock acquisition time; `flock` holds the lock until the callback returns.
- Laravel uses the application's database connection and cache store. Ensure the cache store supports atomic locks and is shared across instances. Set `locks.lease_seconds` longer than the expected maximum generation callback duration; a cache lease can expire while a callback is still running if configured too low.
- Generation rate-limit allowance is reserved before the OTP record is stored. If persistence subsequently fails, the allowance remains consumed. This conservative behaviour avoids reopening a quota after an application/database failure.
- A successful `verifyAndConsume()` is atomic with the OTP record transaction. A successful non-consuming `verify()` leaves the record active but still consumes a verification request from the rate-limit window.
- Expiration is enforced during verification; scheduled cleanup is storage maintenance, not the security boundary.

## Security

Read [docs/SECURITY.md](docs/SECURITY.md) before using this package for authentication or transaction authorization. The consuming application must still implement endpoint/IP rate limiting, secure delivery, CSRF protection, account-state checks and authorization.

## Testing

```bash
composer install
composer test
```

PDO integration tests use SQLite and are skipped when `pdo_sqlite` is unavailable. Laravel adapter tests should be run in each Laravel version and database/cache combination you support. A local syntax check can be run with:

```bash
find src config database tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

## License

MIT. See [LICENSE](LICENSE).
