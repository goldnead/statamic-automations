<?php

namespace Goldnead\StatamicAutomations\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP listing runs before its first query.
 *
 * On 03.09.2026 sibling addons answered HTTP 500 on the public demo for one
 * reason: they were installed and their migrations had never run, so the first
 * query on the listing threw `no such table`. Automations has the same shape,
 * and more of it — the runs, the audit log and the flows themselves each live
 * in their own table, and every one of the five screens reads at least one of
 * them before it renders a row. A missing table is an operator's unfinished
 * setup, not a bug, and it deserves a sentence rather than a stack trace.
 *
 * The reason must not vanish with the 500, though: every guarded page that
 * turns somebody away writes why to the log first. A page that renders an
 * empty state and says nothing anywhere would be worse than the crash it
 * replaced — the install would look finished and never work.
 */
final class Setup
{
    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-automations: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('statamic-automations::SetupRequired', [
            'title' => $title,
            'heading' => __('statamic-automations::setup.setup_required_heading'),
            'description' => __('statamic-automations::setup.setup_required_description'),
            'tables' => $missing,
        ]);
    }

    /**
     * Those of the given tables that this install actually reads.
     *
     * The flow *definitions* have a second home: with
     * `automations.storage.driver` on `flat_file` they are YAML, and the
     * `automations` table is neither written nor read. Naming it in a guard
     * there would send an operator to `php artisan migrate` for a table their
     * site will never use — a wrong sentence is not better than a stack trace.
     * Runs, node runs and the audit log have no flat driver; they are guarded
     * unconditionally, which is also why a flat-file install still needs its
     * migrations for every screen below.
     *
     * @return list<string>
     */
    public static function definitionTables(string ...$tables): array
    {
        return config('automations.storage.driver', 'database') === 'flat_file'
            ? []
            : array_values($tables);
    }
}
