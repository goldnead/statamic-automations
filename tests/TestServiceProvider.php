<?php

namespace Goldnead\StatamicAutomations\Tests;

use Goldnead\StatamicAutomations\ServiceProvider;

/**
 * Test-only service provider that boots the addon eagerly.
 *
 * The production `ServiceProvider` (extends Statamic's
 * `AddonServiceProvider`) defers `bootAddon()` to a
 * `Statamic::booted()` callback. Orchestra Testbench never fires
 * those callbacks because Statamic itself isn't fully booted in a
 * unit-test context, so registries / migrations / listeners would
 * never be hooked up.
 *
 * This provider runs `bootAddon()` directly from `boot()`, ensuring
 * the addon is fully registered for both the test process and any
 * HTTP requests dispatched through the Laravel test kernel.
 */
class TestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootAddon();
    }
}
