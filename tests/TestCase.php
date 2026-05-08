<?php

namespace Goldnead\StatamicAutomations\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Use TestServiceProvider instead of the production one so
        // bootAddon() runs eagerly. See TestServiceProvider for why.
        return [
            TestServiceProvider::class,
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

        // Pro gating is opt-in for hosts but interferes with tests that
        // intentionally register custom actions/triggers. Off by default.
        $app['config']->set('automations.features.custom_actions_requires_pro', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
