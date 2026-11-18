# Architecture

The package has a small framework-independent core and optional adapters.

## Core

- `OtpManager` resolves global and purpose configuration and creates a fresh `PendingOtp` operation.
- `PendingOtp` applies operation-specific overrides, reserves generation quota under a lock, generates a token, hashes it and persists the active record.
- `OtpGenerator` creates numeric or alphanumeric token values using `random_int()`.
- `OtpHasher` normalizes identifiers and creates keyed HMACs bound to both identifier and purpose.
- `OtpVerifier` applies global verification request limits, loads the OTP under a repository transaction/row lock, enforces expiry and failed attempts, and optionally consumes the record.
- Contracts define the clock, lock manager, OTP repository and rate limiter boundaries.

## Adapters

- PDO adapters support MySQL, PostgreSQL and SQLite.
- `FileLockManager` is provided for a single host and must not be used as a distributed lock. The lock contract uses an acquisition wait timeout; Laravel additionally uses a configurable lease duration because cache locks expire automatically.
- Laravel adapters use the configured Laravel database connection and cache store; they do not alter the core.

## Persistence model

One active OTP is identified by the composite key `(identifier_hash, purpose)`. The record stores only the HMAC token hash, expiry Unix timestamp, attempt count, maximum attempts and timestamps. Resending replaces the active record, invalidating the previous token.

## Extension points

Applications can implement `OtpRepositoryInterface`, `RateLimiterInterface`, `LockManagerInterface` or `ClockInterface` to use a different database, distributed lock system, cache or test clock.

Purpose values are restricted to lowercase ASCII letters, digits, dots, underscores and hyphens so database collation differences do not make case variants collide.
