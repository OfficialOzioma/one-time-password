# Implementation security review (release-candidate review)

This is an engineering review of the package implementation, not a third-party penetration test or formal security audit.

## Checks performed

- Token generation uses `random_int()` for each digit or character; no weak PRNG is used.
- Token and identifier values are HMAC-SHA256 hashed with separate secrets before database persistence.
- Token HMAC input includes both the identifier hash and purpose.
- HMAC keys must be independent and at least 32 bytes.
- Generated OTP plaintext is kept outside normal object properties, omitted from JSON/debug/export output, and PHP serialization/cloning are prohibited.
- OTP expiry is enforced on verification, independently of cleanup scheduling.
- Incorrect attempts are persisted under a transaction/row lock.
- Successful consumption deletes the record in the same transaction that verified it.
- Verification request limits count requests even when no OTP record exists, reducing identifier/record enumeration.
- Generation cooldown and generation quotas are checked and reserved atomically by the PDO adapter; Laravel uses cache locks around bucket operations.
- SQL values are parameterized. Configurable SQL table identifiers are restricted to simple identifier characters.
- PDO adapters support only MySQL, PostgreSQL and SQLite and select the relevant upsert/locking syntax.
- File locks are restricted to private, non-symlink directories and are documented as single-host only. The interface consistently treats its timeout as the maximum time to acquire; the OS file lock remains held until the callback returns.
- Laravel validation rejects a blank identifier without throwing and uses a generic validation error by default.

## Known operational limits

- The supplied `FileLockManager` is not distributed. Multi-host plain PHP deployments must provide a distributed `LockManagerInterface` implementation.
- The Laravel cache store must support atomic locks and be shared by all application instances. The configured lease must outlast the generation callback.
- PDO integration and row-lock behaviour must be exercised against the exact production database version. SQLite tests are not a substitute for MySQL/PostgreSQL concurrency tests.
- In PDO mode, rate-limit allowance is reserved before OTP persistence. If the subsequent OTP insert/update fails, the generation allowance remains consumed. This is conservative and may require the user to wait before retrying.
- The Laravel bridge is included with a Testbench smoke test, but package maintainers should run the full test matrix against each supported Laravel major and cache/database driver before tagging a release.
- Delivery security is outside this package. Applications must secure email/SMS providers, queue payloads, logs, endpoints, sessions, CSRF, account-state checks and authorization.

## Release gate

Before using the package for production authentication or financial authorization:

1. Run `composer validate` and `composer test` in a Composer-enabled environment.
2. Run PDO integration tests with `pdo_sqlite`, then exercise MySQL/InnoDB and PostgreSQL adapters against real services.
3. Run Laravel Testbench or application integration tests for every Laravel version declared as supported.
4. Test concurrent generation and verification across multiple workers and hosts with the actual shared lock/cache backend.
5. Review key management, queue encryption, endpoint IP limits, workflow authorization and error logging in the consuming application.
