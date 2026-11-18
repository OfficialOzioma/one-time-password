# Testing

Install the development dependencies:

```bash
composer install
```

Run all tests:

```bash
composer test
```

Run the framework-independent unit tests:

```bash
composer test:unit
```

Run PDO adapter integration tests:

```bash
composer test:integration
```

The PDO integration suite skips itself if `pdo_sqlite` is not available. To validate MySQL or PostgreSQL behaviour, run the same repository/rate-limit scenarios in an environment with the corresponding PDO driver and database service.

Laravel integration should be exercised in each supported Laravel major version with Orchestra Testbench or an application-level test suite. Verify service-provider discovery, publishing, migrations, cache locks and validation-rule consumption using the target cache and database drivers.

A simple syntax pass is also useful:

```bash
find src config database tests examples -name '*.php' -print0 | xargs -0 -n1 php -l
```
