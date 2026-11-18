<?php

declare(strict_types=1);

namespace Ozioma\Otp\Laravel;

use Illuminate\Support\ServiceProvider;
use Ozioma\Otp\Adapters\Laravel\LaravelLockManager;
use Ozioma\Otp\Adapters\Laravel\LaravelOtpRepository;
use Ozioma\Otp\Adapters\Laravel\LaravelRateLimiter;
use Ozioma\Otp\Contracts\ClockInterface;
use Ozioma\Otp\Contracts\LockManagerInterface;
use Ozioma\Otp\Contracts\OtpRepositoryInterface;
use Ozioma\Otp\Contracts\RateLimiterInterface;
use Ozioma\Otp\Core\OtpGenerator;
use Ozioma\Otp\Core\OtpHasher;
use Ozioma\Otp\Core\OtpManager;
use Ozioma\Otp\Core\OtpVerifier;
use Ozioma\Otp\Core\SystemClock;
use Ozioma\Otp\Laravel\Commands\PruneExpiredOtps;

final class OtpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = dirname(__DIR__, 2);
        $defaults = require $root . '/config/otp.php';
        $applicationConfig = $this->app['config']->get('otp', []);

        // Merge recursively so partial nested overrides retain package defaults.
        $this->app['config']->set(
            'otp',
            array_replace_recursive(
                $defaults,
                is_array($applicationConfig) ? $applicationConfig : []
            )
        );

        $this->app->singleton(ClockInterface::class, SystemClock::class);

        $this->app->singleton(OtpHasher::class, function ($app): OtpHasher {
            return new OtpHasher(
                (string) $app['config']->get('otp.hash_keys.token', ''),
                (string) $app['config']->get('otp.hash_keys.identifier', ''),
            );
        });

        $this->app->singleton(LockManagerInterface::class, function ($app): LockManagerInterface {
            return new LaravelLockManager(
                $app->make('cache'),
                $app['config']->get('otp.cache_store'),
                (int) $app['config']->get('otp.locks.lease_seconds', 60),
            );
        });

        $this->app->singleton(RateLimiterInterface::class, function ($app): RateLimiterInterface {
            return new LaravelRateLimiter(
                $app->make('cache'),
                $app->make(LockManagerInterface::class),
                $app['config']->get('otp.cache_store'),
            );
        });

        $this->app->singleton(OtpRepositoryInterface::class, function ($app): OtpRepositoryInterface {
            $connection = $app['db']->connection($app['config']->get('otp.connection'));
            return new LaravelOtpRepository(
                $connection,
                (string) $app['config']->get('otp.table', 'otps'),
            );
        });

        $this->app->singleton(OtpGenerator::class);

        $this->app->singleton(OtpVerifier::class, function ($app): OtpVerifier {
            return new OtpVerifier(
                $app->make(OtpRepositoryInterface::class),
                $app->make(OtpHasher::class),
                $app->make(RateLimiterInterface::class),
                $app->make(ClockInterface::class),
                (array) $app['config']->get('otp', []),
            );
        });

        $this->app->singleton(OtpManager::class, function ($app): OtpManager {
            return new OtpManager(
                $app->make(OtpRepositoryInterface::class),
                $app->make(OtpGenerator::class),
                $app->make(OtpHasher::class),
                $app->make(OtpVerifier::class),
                $app->make(RateLimiterInterface::class),
                $app->make(LockManagerInterface::class),
                $app->make(ClockInterface::class),
                (array) $app['config']->get('otp', []),
            );
        });
    }

    public function boot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->publishes([
            $root . '/config/otp.php' => config_path('otp.php'),
        ], 'otp-config');



        // Publish the migration directory to Laravel's migrations directory.
        // publishesMigrations() will assign a fresh timestamp to the migration.
        $this->publishesMigrations([
            $root . '/database/migrations/' => database_path('migrations'),
        ], 'otp-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneExpiredOtps::class]);
        }
    }
}
