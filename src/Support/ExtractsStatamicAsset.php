<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * Shared helper to flatten a Statamic asset (from an event) into a plain
 * array for the automation context. Tolerant of array events (tests) and
 * of partial Statamic API surfaces across versions.
 */
trait ExtractsStatamicAsset
{
    /**
     * @return array<string, mixed>
     */
    protected function extractAsset(object|array $event): array
    {
        if (is_array($event)) {
            return $event['asset'] ?? [];
        }

        $asset = $event->asset ?? null;
        if (! is_object($asset)) {
            return [];
        }

        $container = method_exists($asset, 'container') ? $asset->container() : null;

        return [
            'id' => method_exists($asset, 'id') ? $asset->id() : null,
            'filename' => method_exists($asset, 'filename') ? $asset->filename() : null,
            'basename' => method_exists($asset, 'basename') ? $asset->basename() : null,
            'container' => is_object($container) && method_exists($container, 'handle') ? $container->handle() : null,
            'url' => method_exists($asset, 'url') ? $asset->url() : null,
            'data' => method_exists($asset, 'data')
                ? (is_object($asset->data()) && method_exists($asset->data(), 'all')
                    ? $asset->data()->all()
                    : (array) $asset->data())
                : [],
        ];
    }

    protected function assetMatchesScope(array $asset, array $config): bool
    {
        $container = $config['container'] ?? null;

        if ($container && ($asset['container'] ?? null) !== $container) {
            return false;
        }

        return true;
    }
}
