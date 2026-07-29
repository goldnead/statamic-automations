<?php

use Goldnead\StatamicAutomations\Tests\Fixtures\AutomationsDataFixture;

/**
 * The migrations, run against a database that already holds data.
 *
 * Every migration check this addon had ran against empty tables, because every
 * bed it had was a fresh install. That is not a thin spot in the coverage, it
 * is the coverage pointing away from the only case a migration can be wrong
 * about: a table with rows in it, created by an older release.
 *
 * What it let through is documented at length in
 * `2026_07_24_100002_add_brand_id_to_automations_tables`. In short: that
 * migration adds a column to seven tables and then has two places left where
 * it can abort. Neither engine rolls DDL back and an aborted migration is not
 * recorded as run, so the retry — the only thing an operator can reasonably do
 * next — used to die on `duplicate column name: brand_id`, an error about the
 * first statement in the file that says nothing at all about the state the
 * database is actually in.
 *
 * Two properties of this file matter more than the individual cases:
 *
 * 1. It names no migration file. It walks `database/migrations/` and seeds a
 *    fresh generation of data into every table that exists *before each*
 *    migration, so a migration added three years from now is covered by this
 *    test the day it is committed, without anybody remembering to come back
 *    here. A test that lists the two files that were once broken only ever
 *    tests the past.
 *
 * 2. Every assertion about the handle guarantee is behavioural. "The migration
 *    ran" and "the constraint is there" are not the same statement, and
 *    mistaking one for the other is the entire class of defect — so nothing
 *    here checks an exit code or an index name. It writes the row the
 *    constraint is supposed to refuse and requires the database to refuse it.
 */
it('runs every migration against tables that already hold rows', function (): void {
    $fixture = new AutomationsDataFixture($this->isolated());
    $batch = 0;

    /** @var array<string, int> $high the most rows each table has ever held */
    $high = [];

    // Seed before each migration, not just at the start: a migration that only
    // ever meets rows written by *earlier* migrations' schema is still only
    // being tested against a fresh install with a bit of data in it.
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch, &$high): void {
        $fixture->seed($batch++);

        foreach ($fixture->counts() as $table => $count) {
            $high[$table] = max($high[$table] ?? 0, $count);
        }
    });

    // The handle unique still bites, on the last generation seeded — the one
    // that went in while the schema was at its most nearly-final.
    expect($this->duplicateHandleIsAccepted(AutomationsDataFixture::handleProbe($batch - 1)))
        ->toBeFalse('the handle unique does not bite after a stepwise migration over populated tables');

    // Nothing that was seeded may have gone missing. A migration is allowed to
    // add rows and to rewrite them; it is not allowed to lose them.
    foreach ($high as $table => $count) {
        expect($this->isolated()->table($table)->count())
            ->toBeGreaterThanOrEqual($count, "rows disappeared from {$table}");
    }

    expect($this->isolated()->table('automations')->whereNull('brand_id')->count())->toBe(0);
});

/**
 * The same walk, but starting from an install that really existed.
 *
 * The sets under `tests/Fixtures/released-migrations/` are the migration
 * directories of published tags, taken verbatim with
 * `git show <tag>:database/migrations/<file>`. They are fixtures, not code:
 * nothing in them may be edited to make a test pass, because the whole point
 * is that they are what is on somebody's server right now.
 *
 *  - v1.2.0 predates brand scoping entirely. `automations.handle` still
 *    carries a global unique and no table has a brand_id, so this is the case
 *    where the brand migration does all of its work at once, on rows.
 *  - v1.5.0 is the release brand scoping shipped in, with the version of the
 *    migration that did not yet require brand_id to be NOT NULL.
 *  - v1.6.1 is head. Nothing new runs, which is its own thing worth proving:
 *    an install that is already current must not be damaged by a migrate run.
 */
it('upgrades a populated install from every released schema', function (string $version): void {
    // The install as it stood on that release, with its data.
    $this->migratePath($this->releasedMigrations($version));

    $fixture = new AutomationsDataFixture($this->isolated());

    expect($fixture->seed(0))->toBe(count(AutomationsDataFixture::AUTOMATIONS));

    $before = $fixture->counts();

    // Then the upgrade, with the tables filling up further as it goes.
    $batch = 1;
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch): void {
        $fixture->seed($batch++);
    });

    expect($this->duplicateHandleIsAccepted(AutomationsDataFixture::handleProbe(0)))
        ->toBeFalse("the handle unique does not bite after upgrading a populated {$version} install");

    foreach ($before as $table => $count) {
        expect($this->isolated()->table($table)->count())
            ->toBeGreaterThanOrEqual($count, "rows disappeared from {$table} while upgrading {$version}");
    }

    expect($this->isolated()->table('automations')->whereNull('brand_id')->count())
        ->toBe(0, "automations rows were left without a brand_id after upgrading {$version}");
})->with(['v1.2.0', 'v1.5.0', 'v1.6.1']);
