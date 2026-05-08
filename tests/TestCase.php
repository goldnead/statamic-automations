<?php

namespace Goldnead\StatamicAutomations\Tests;

use Goldnead\StatamicAutomations\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Statamic's AddonServiceProvider defers its `bootAddon()` to a
        // `Statamic::booted()` callback, which Orchestra Testbench
        // doesn't fire because Statamic itself is never fully booted in
        // a unit-test context. Call our addon's bootstrap directly so
        // registries, listeners and migrations are available.
        $provider = $this->app->getProvider(ServiceProvider::class);
        if ($provider !== null && method_exists($provider, 'bootAddon')) {
            $provider->bootAddon();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Stable APP_KEY so Crypt-based casts (EncryptedJson) work in tests.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // The Pro-tier gate is opt-in for hosts but interferes with tests
        // that intentionally register custom actions/triggers. Turn it off
        // by default; individual tests can opt back in via config()->set.
        $app['config']->set('automations.features.custom_actions_requires_pro', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
