<?php

namespace Goldnead\StatamicAutomations\Tests;

use Closure;
use Illuminate\Http\Request;
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

        // Statamic's CP-authenticated middleware expects a fully booted
        // Statamic CP — Orchestra Testbench does not provide that. We
        // override the middleware name with a *group* (not a single
        // alias) that explicitly includes SubstituteBindings so
        // route-model binding still works for {automation}, etc.
        // Aliasing it to a single no-op middleware would silently drop
        // SubstituteBindings and feature tests would receive empty
        // model instances.
        $app['router']->middlewareGroup('statamic.cp.authenticated', [
            NoopAuthMiddleware::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}

/**
 * No-op middleware that lets HTTP feature tests run without booting
 * Statamic's full CP auth stack. Production code never registers
 * this — it only exists inside the test process.
 */
class NoopAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
