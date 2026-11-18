# Security guidance

Ozioma OTP provides primitives for generating and verifying one-time codes. It does not make an application secure by itself. The consuming application remains responsible for authorization, delivery and workflow design.

## Secrets and hashing

- Use independent, random secrets of at least 32 bytes for `OTP_HASH_KEY` and `OTP_IDENTIFIER_HASH_KEY`.
- Store secrets in a secret manager or environment configuration, not source control or a database.
- All application instances must use identical secrets.
- Rotating a key invalidates outstanding records. Plan rotation around the maximum OTP lifetime.
- OTP values and identifiers are HMAC-SHA256 hashed before persistence. Do not add plaintext OTP columns or log generated tokens.
- `GeneratedOtp` exposes the token for delivery. Keep the object inside trusted application code. JSON serialization and debug output redact the token, but direct property access remains possible by design.

## Tokens and purposes

- Default numeric tokens are six digits. Four-digit codes are supported for compatibility but provide less entropy; use at least six digits for account authentication and consider longer values for sensitive operations.
- Use distinct, stable purpose names, such as `login`, `password_reset` and `transaction_authorization`.
- The identifier and purpose are both bound into the token HMAC.
- Only one active OTP exists for an identifier and purpose. Include a transaction or resource ID in the identifier if concurrent operations need separate OTPs.
- `verifyAndConsume()` should normally be used for authentication and authorization. `verify()` is non-consuming but still counts against verification request limits.
- Never let a client choose an arbitrary purpose. Set the purpose in trusted server-side code.

## Rate limiting and locking

- Keep per-OTP `max_attempts`, generation cooldown, generation quota and global verification rate limits enabled.
- Add HTTP/IP-level rate limiting to endpoints that generate or verify OTPs.
- The plain PHP `FileLockManager` works only when all workers share one local filesystem. It is not suitable for multi-host deployments. Its timeout limits how long a process waits to acquire the lock; `flock` holds the lock until the callback returns.
- Use a distributed lock implementation for multiple hosts. Laravel deployments should use a shared cache backend supporting atomic locks.
- Use MySQL InnoDB or PostgreSQL for production row-level locking. SQLite is suitable for development and small single-process deployments, not high-contention multi-worker authentication workloads.
- Ensure application/database clocks are synchronized. OTP expiry uses the clock injected into the package and timestamps stored as Unix seconds.

## Delivery

- Deliver codes only over a channel appropriate to the risk of the operation.
- Do not include OTPs in URLs, analytics events, exception messages or application logs.
- Queue delivery if appropriate, but protect queue payloads because the plaintext OTP must be available to the mail/SMS worker. Use encrypted queue payloads or an equivalent protected queue transport.
- Do not return the OTP from a public API response.
- Resending generates a new token and invalidates the previous token.

## Validation and workflow

- Laravel's `OtpRule` consumes a successful OTP by default. A validation rule with side effects can consume a code before later business checks fail. Place it after other relevant checks or use `consumeOnSuccess: false` and explicitly call `verifyAndConsume()` after all preconditions pass.
- Check account status and authorization before consuming a code.
- An OTP only proves control of the delivery channel at that time. It does not prove identity or authorize every action.
- Use CSRF protection for browser forms and authenticate/authorize API callers separately.
- Return generic errors where exposing whether an identifier has an active OTP would enable account enumeration.

## Storage

- Restrict database access; the database contains pseudonymous identifier hashes and token hashes but still contains authentication metadata.
- Restrict the plain PHP lock directory to the application user and keep it outside the public web root.
- Run expired-record cleanup periodically. Verification checks expiry even when cleanup has not run.
- Monitor failed delivery and rate-limit events without logging token values.

## Review before production

Run the automated tests against the same database engine and cache backend used in production. Exercise concurrent generation and verification requests across multiple application instances. Review queue encryption, secret distribution, IP limits, account state checks and operational logging in the consuming application.
