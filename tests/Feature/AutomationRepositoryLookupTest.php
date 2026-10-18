<?php

namespace Goldnead\StatamicAutomations\Tests\Feature;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Tests\TestCase;

/**
 * A UUID is not a number. On MySQL `where id = '1b2c…'` reads the leading digits
 * and matches id 1, so the lookup by UUID returned the wrong automation whenever a
 * UUID began with the id of another one. SQLite compares strings and never showed it,
 * which is why a run could resume on the wrong graph only on production.
 */
class AutomationRepositoryLookupTest extends TestCase
{
    private function makeAutomations(): array
    {
        $first = Automation::create(['name' => 'First', 'handle' => 'first', 'enabled' => true]);
        $second = Automation::create(['name' => 'Second', 'handle' => 'second', 'enabled' => true]);
        // A UUID that starts with the id of the first automation.
        $second->forceFill(['uuid' => substr($first->id.'b2c3d4e5-0000-4000-8000-000000000001', 0, 36)])->save();

        return [$first->fresh(), $second->fresh()];
    }

    public function test_find_by_uuid_returns_the_automation_the_uuid_names(): void
    {
        [$first, $second] = $this->makeAutomations();

        $this->assertSame($second->id, app(AutomationRepository::class)->find($second->uuid)?->id);
        $this->assertSame($first->id, app(AutomationRepository::class)->find($first->uuid)?->id);
    }

    public function test_find_by_a_numeric_id_still_works(): void
    {
        [$first] = $this->makeAutomations();

        $this->assertSame($first->id, app(AutomationRepository::class)->find($first->id)?->id);
        $this->assertSame($first->id, app(AutomationRepository::class)->find((string) $first->id)?->id);
    }

    public function test_find_by_ref_takes_handle_uuid_or_id(): void
    {
        [$first, $second] = $this->makeAutomations();
        $repository = app(AutomationRepository::class);

        $this->assertSame($second->id, $repository->findByRef($second->uuid)?->id);
        $this->assertSame($second->id, $repository->findByRef('second')?->id);
        $this->assertSame($first->id, $repository->findByRef((string) $first->id)?->id);
        $this->assertNull($repository->findByRef('does-not-exist'));
    }
}
