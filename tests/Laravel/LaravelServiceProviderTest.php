<?php

declare(strict_types=1);

namespace Ozioma\Otp\Tests\Laravel;

use Ozioma\Otp\Core\OtpManager;
use Ozioma\Otp\Laravel\OtpServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;

final class LaravelServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [OtpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('otp.hash_keys.token', str_repeat('t', 64));
        $app['config']->set('otp.hash_keys.identifier', str_repeat('i', 64));
        $app['config']->set('otp.cache_store', 'array');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }


    public function test_config_is_published_to_the_application_config_directory(): void
    {
        $paths = ServiceProvider::pathsToPublish(OtpServiceProvider::class, 'otp-config');

        self::assertContains(config_path('otp.php'), array_values($paths));
        self::assertIsArray(require dirname(__DIR__, 2) . '/config/otp.php');
    }

    public function test_migration_directory_is_published_to_the_migrations_directory(): void
    {
        $paths = ServiceProvider::pathsToPublish(OtpServiceProvider::class, 'otp-migrations');

        self::assertNotEmpty($paths);

        $sourceDirectory = array_key_first($paths);

        self::assertDirectoryExists($sourceDirectory);
        self::assertSame(database_path('migrations'), $paths[$sourceDirectory]);
    }

    public function test_service_provider_registers_the_core_manager(): void
    {
        $manager = $this->app->make(OtpManager::class);
        self::assertInstanceOf(OtpManager::class, $manager);
    }
}
