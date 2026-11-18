# Changelog

## Unreleased

- Publish migrations using Laravel's directory-to-directory `publishesMigrations` mapping.
- Publish an idiomatic Laravel `config/otp.php` using `env()` and keep a separate portable `config/otp-plain.php` for non-Laravel applications.
- Merge Laravel package defaults recursively so partial nested application overrides retain the remaining defaults.

## 0.1.0 - Unreleased

- Initial framework-independent OTP core.
- Numeric and alphanumeric token generation.
- HMAC-SHA256 hashing for identifiers and tokens.
- Purpose-specific configuration and fluent per-operation overrides.
- PDO storage and database-backed rate-limiting adapters.
- Single-host filesystem locking adapter.
- Optional Laravel service provider, facade, validation rule, migration and cleanup command.
- Documentation for Laravel and plain PHP integration.
