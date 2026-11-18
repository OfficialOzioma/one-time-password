# Installation and compatibility

## Requirements

- PHP 8.2 or newer.
- Composer 2.
- PDO (`ext-pdo`) for the supplied database adapters.
- One of `pdo_mysql`, `pdo_pgsql` or `pdo_sqlite` for the corresponding PDO adapter.
- Laravel 11, 12 or 13 only when using the optional Laravel bridge.

The core has no Laravel dependency. Laravel classes are loaded only when Laravel's package discovery registers the service provider.

## Install from a local path before publication

Add a path repository to the consuming application's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../ozioma-otp",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "ozioma/otp": "@dev"
  }
}
```

Then run:

```bash
composer update ozioma/otp
```

For a VCS repository, push the package to Git and add it as a Composer VCS repository. Once published, consumers can use `composer require ozioma/otp` without extra repository configuration.

## Laravel

```bash
composer require ozioma/otp
php artisan vendor:publish --tag=otp-config
php artisan vendor:publish --tag=otp-migrations
php artisan migrate
```

The migration publisher maps the package's migration directory to your application's existing `database/migrations` directory. It does not try to create a file named `database/migrations`.

The `otp-config` tag publishes the Laravel-style `config/otp.php` file into your application's `config/` directory. This is a standard Laravel PHP configuration file that returns an array; it is not a class. The package's plain PHP configuration is separate (`config/otp-plain.php`) and is not published into Laravel applications.

Add two independently generated 32-byte-or-longer secrets to the environment:

```dotenv
OTP_HASH_KEY=...
OTP_IDENTIFIER_HASH_KEY=...
```

The Laravel adapter uses the framework database connection and cache store. Configure a shared cache backend that supports atomic locks in multi-server environments. Set `locks.lease_seconds` longer than the expected maximum duration of the generation callback.

## Plain PHP

Install with Composer, load `vendor/autoload.php`, run the SQL schema for your PDO driver and construct `OtpManager` using `PdoOtpRepository`, `PdoRateLimiter`, `FileLockManager`, `SystemClock`, `OtpGenerator`, `OtpHasher` and `OtpVerifier`. A complete example is in [README.md](../README.md#plain-php).

Keep the filesystem lock directory outside the public document root with restrictive permissions. For multi-host deployments, supply your own distributed `LockManagerInterface` implementation.
