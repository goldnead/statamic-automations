<?php

namespace Goldnead\StatamicAutomations\Tests;

use Goldnead\StatamicAutomations\Tests\Concerns\DrivesMigrationsOnAnIsolatedDatabase;

/**
 * A bed for migrating a database by hand, from any released schema forward.
 *
 * Everything this does lives in
 * {@see DrivesMigrationsOnAnIsolatedDatabase}, which explains why a connection
 * of its own is necessary. This class exists to bind the lifecycle: a fresh,
 * empty, brand-context-only database before every test, and no temp file left
 * behind afterwards. `tests/Migrations` runs on it (see tests/Pest.php).
 */
abstract class MigrationPathTestCase extends TestCase
{
    use DrivesMigrationsOnAnIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropIsolatedSqliteFile();

        parent::tearDown();
    }
}
