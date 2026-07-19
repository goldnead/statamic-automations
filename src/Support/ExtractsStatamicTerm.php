<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * Shared helper to flatten a Statamic taxonomy term (from an event) into a
 * plain array for the automation context. Tolerant of array events (tests)
 * and of partial Statamic API surfaces across versions.
 */
trait ExtractsStatamicTerm
{
    /**
     * @return array<string, mixed>
     */
    protected function extractTerm(object|array $event): array
    {
        if (is_array($event)) {
            return $event['term'] ?? [];
        }

        $term = $event->term ?? null;
        if (! is_object($term)) {
            return [];
        }

        return [
            'id' => method_exists($term, 'id') ? $term->id() : null,
            'title' => method_exists($term, 'title') ? $term->title() : null,
            'slug' => method_exists($term, 'slug') ? $term->slug() : null,
            'taxonomy' => method_exists($term, 'taxonomyHandle') ? $term->taxonomyHandle() : null,
        ];
    }

    protected function termMatchesScope(array $term, array $config): bool
    {
        $taxonomy = $config['taxonomy'] ?? null;

        if ($taxonomy && ($term['taxonomy'] ?? null) !== $taxonomy) {
            return false;
        }

        return true;
    }
}
