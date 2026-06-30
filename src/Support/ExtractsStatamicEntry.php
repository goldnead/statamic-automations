<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * Shared helper to flatten a Statamic entry (from an event) into a plain
 * array for the automation context. Tolerant of array events (tests) and
 * of partial Statamic API surfaces across versions.
 */
trait ExtractsStatamicEntry
{
    /**
     * @return array<string, mixed>
     */
    protected function extractEntry(object|array $event): array
    {
        if (is_array($event)) {
            return $event['entry'] ?? [];
        }

        $entry = $event->entry ?? null;
        if (! is_object($entry)) {
            return [];
        }

        return [
            'id' => method_exists($entry, 'id') ? $entry->id() : null,
            'title' => method_exists($entry, 'get') ? $entry->get('title') : null,
            'slug' => method_exists($entry, 'slug') ? $entry->slug() : null,
            'collection' => method_exists($entry, 'collectionHandle')
                ? $entry->collectionHandle()
                : (method_exists($entry, 'collection') && is_object($entry->collection())
                    ? $entry->collection()->handle()
                    : null),
            'site' => method_exists($entry, 'locale') ? $entry->locale() : null,
            'url' => method_exists($entry, 'url') ? $entry->url() : null,
            'data' => method_exists($entry, 'data')
                ? (is_object($entry->data()) && method_exists($entry->data(), 'all')
                    ? $entry->data()->all()
                    : (array) $entry->data())
                : [],
        ];
    }

    protected function entryMatchesScope(array $entry, array $config): bool
    {
        $collection = $config['collection'] ?? null;
        $site = $config['site'] ?? null;

        if ($collection && ($entry['collection'] ?? null) !== $collection) {
            return false;
        }
        if ($site && ($entry['site'] ?? null) !== $site) {
            return false;
        }

        return true;
    }
}
