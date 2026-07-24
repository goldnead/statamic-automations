<?php

namespace Goldnead\StatamicAutomations\Tests;

use Goldnead\StatamicAutomations\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Statamic\Facades\User;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Statamic's AddonServiceProvider runs bootAddon() inside a
        // Statamic::booted(...) callback. orchestra/testbench doesn't fire
        // those callbacks, so nodes, routes, permissions, listeners and the
        // rest of bootAddon never register. Force it so feature tests can hit
        // the real CP routes with the real middleware and ACLs in place.
        $provider = $this->app->getProvider(ServiceProvider::class);
        if ($provider instanceof ServiceProvider) {
            $provider->bootAddon();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            \Goldnead\BrandContext\ServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Stable APP_KEY so Crypt-based casts (EncryptedJson) work in tests.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Use Statamic's flat-file user repository so feature tests can create
        // real CP super users and hit the authenticated CP routes.
        $app['config']->set('statamic.users.repository', 'file');

        // Pro gating is opt-in for hosts but interferes with tests that
        // intentionally register custom actions/triggers. Off by default.
        $app['config']->set('automations.features.custom_actions_requires_pro', false);
    }

    /**
     * Statamic registers addon CP routes inside Statamic::booted callbacks
     * that orchestra/testbench doesn't fire. For feature tests we mount them
     * ourselves under the `statamic.cp.` name prefix and `/cp` URL prefix that
     * production uses, so `cp_route('statamic-automations.*')` resolves exactly
     * as it does in a real Control Panel.
     */
    protected function defineRoutes($router): void
    {
        // SubstituteBindings is part of Statamic's CP route group in
        // production; mount it here so implicit route-model binding for
        // {automation}, {run}, {nodeRun} resolves to real Eloquent models.
        $router->name('statamic.cp.')
            ->prefix('cp')
            ->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
            ->group(__DIR__ . '/../routes/cp.php');
    }

    /**
     * Create and authenticate a Statamic super user — the standard actor for
     * CP feature tests. Mirrors how a real Control Panel request is made.
     */
    protected function actingAsSuperUser(): \Statamic\Contracts\Auth\User
    {
        $user = User::make()
            ->email('admin@example.com')
            ->makeSuper();
        $user->save();

        $this->actingAs($user);

        return $user;
    }
}
