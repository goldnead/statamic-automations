<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Nodes\Logic\DelayNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guards the back-fill for Delay nodes saved before 1.5.2.
 *
 * Until 1.5.2 the config panel rendered the "Minutes" default without ever
 * writing it into the model, so those nodes sit in the database with an
 * `amount` and no `unit` — permanently red in the editor, and running as
 * minutes. The migration completes them.
 */
const DELAY_UNIT_BACKFILL_MIGRATION = __DIR__
    . '/../../database/migrations/2026_07_28_000001_backfill_delay_node_unit.php';

/** Insert a node the way a pre-1.5.2 save left it, bypassing the brand scope. */
function delayBackfillNode(array $attributes = []): int
{
    $automationId = DB::table('automations')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Backfill fixture',
        'handle' => 'backfill-' . Str::random(6),
        'enabled' => true,
        'version' => 1,
        // A raw insert is exactly the writer that used to leave brand_id NULL
        // and slip out from under `unique(brand_id, handle)`. The column is
        // NOT NULL since 1.5.4, so the fixture now has to say what a real row
        // says — which is the point of the constraint.
        'brand_id' => app('brand-context')->currentId(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('automation_nodes')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'automation_id' => $automationId,
        'node_key' => 'delay_' . Str::random(4),
        'type' => 'delay',
        'position_x' => 0,
        'position_y' => 0,
        'disabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

function delayBackfillRun(): void
{
    (require DELAY_UNIT_BACKFILL_MIGRATION)->up();
}

function delayBackfillConfig(int $id): ?array
{
    $raw = DB::table('automation_nodes')->where('id', $id)->value('config');

    return $raw === null ? null : json_decode($raw, true);
}

/**
 * MySQL stores `json` columns as a normalised binary document and hands the
 * object back with its keys reordered. The migration preserves keys, not key
 * order — nothing reads these configs positionally — so assertions sort both
 * sides before comparing instead of pretending the order is meaningful.
 */
function delayBackfillCanonical(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(fn ($v) => delayBackfillCanonical($v), $value);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

function expectConfig(int $id)
{
    return expect(delayBackfillCanonical(delayBackfillConfig($id)));
}

it('writes the unit a pre-1.5.2 Delay node is missing', function () {
    $id = delayBackfillNode(['config' => json_encode(['amount' => 5])]);

    // Precondition: exactly the state 1.5.2 left behind — required field absent.
    expect(delayBackfillConfig($id))->not->toHaveKey('unit');

    delayBackfillRun();

    expectConfig($id)->toBe(delayBackfillCanonical(['amount' => 5, 'unit' => 'minutes']));
});

it('writes the unit the node already behaves as, not a nicer one', function () {
    // The migration must record existing behaviour. Prove that behaviour from
    // DelayNode itself rather than trusting the constant: a config without a
    // unit and a config with "minutes" must wait for the same number of seconds.
    $node = new DelayNode();
    $context = AutomationContext::make([], testMode: true);

    $withoutUnit = $node->execute($context, ['amount' => 7])->output['would_wait_seconds'];
    $withMinutes = $node->execute($context, ['amount' => 7, 'unit' => 'minutes'])->output['would_wait_seconds'];

    expect($withoutUnit)->toBe($withMinutes);

    $id = delayBackfillNode(['config' => json_encode(['amount' => 7])]);
    delayBackfillRun();

    $backfilled = delayBackfillConfig($id)['unit'];
    $withBackfilled = $node->execute($context, ['amount' => 7, 'unit' => $backfilled])->output['would_wait_seconds'];

    // The back-filled unit reproduces the wait the node had all along.
    expect($withBackfilled)->toBe($withoutUnit);
});

it('leaves an explicitly chosen unit alone', function () {
    $id = delayBackfillNode(['config' => json_encode(['amount' => 2, 'unit' => 'days'])]);

    delayBackfillRun();

    expectConfig($id)->toBe(delayBackfillCanonical(['amount' => 2, 'unit' => 'days']));
});

it('keeps every other key in the config untouched', function () {
    $original = [
        'amount' => 3,
        'label' => 'Warte kurz',
        'nested' => ['a' => 1, 'b' => [true, null, 'x']],
        'zero' => 0,
    ];

    $id = delayBackfillNode(['config' => json_encode($original)]);

    delayBackfillRun();

    expectConfig($id)->toBe(delayBackfillCanonical($original + ['unit' => 'minutes']));
});

it('does not touch nodes of another type', function () {
    $id = delayBackfillNode([
        'type' => 'send_email',
        'config' => json_encode(['to' => 'a@b.test']),
    ]);

    delayBackfillRun();

    expectConfig($id)->toBe(delayBackfillCanonical(['to' => 'a@b.test']));
});

it('does not rewrite a config it cannot parse', function () {
    // MySQL's `json` column type refuses to store malformed text in the first
    // place, so this state can only exist where the column is untyped (SQLite,
    // or a legacy text column). The guard in the migration is for those.
    if (DB::connection()->getDriverName() === 'mysql') {
        $this->markTestSkipped('MySQL json columns cannot hold malformed JSON.');
    }

    $id = delayBackfillNode(['config' => 'not json at all']);

    delayBackfillRun();

    expect(DB::table('automation_nodes')->where('id', $id)->value('config'))
        ->toBe('not json at all');
});

it('completes a Delay node that has no config at all', function () {
    $id = delayBackfillNode(['config' => null]);

    delayBackfillRun();

    expectConfig($id)->toBe(['unit' => 'minutes']);
});

it('runs twice without changing anything the second time', function () {
    $id = delayBackfillNode(['config' => json_encode(['amount' => 5])]);

    delayBackfillRun();
    $after = DB::table('automation_nodes')->where('id', $id)->value('config');

    delayBackfillRun();

    expect(DB::table('automation_nodes')->where('id', $id)->value('config'))->toBe($after);
});

it('leaves updated_at alone — the repair is not a user edit', function () {
    $stamp = '2020-01-01 00:00:00';
    $id = delayBackfillNode(['config' => json_encode(['amount' => 5]), 'updated_at' => $stamp]);

    delayBackfillRun();

    expect(DB::table('automation_nodes')->where('id', $id)->value('updated_at'))
        ->toStartWith('2020-01-01 00:00:00');
});

it('has no down(): a rollback would strip deliberate units', function () {
    $id = delayBackfillNode(['config' => json_encode(['amount' => 5])]);
    $migration = require DELAY_UNIT_BACKFILL_MIGRATION;

    $migration->up();
    $migration->down();

    // down() is intentionally empty — the unit stays.
    expectConfig($id)->toBe(delayBackfillCanonical(['amount' => 5, 'unit' => 'minutes']));
});

it('reaches every brand and mixes none of them up', function () {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brandA = DB::table('brands')->insertGetId([
        'handle' => 'brand-a', 'name' => 'Brand A', 'is_default' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $brandB = DB::table('brands')->insertGetId([
        'handle' => 'brand-b', 'name' => 'Brand B', 'is_default' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $nodeA = delayBackfillNode(['brand_id' => $brandA, 'config' => json_encode(['amount' => 5])]);
    $nodeB = delayBackfillNode(['brand_id' => $brandB, 'config' => json_encode(['amount' => 9])]);

    delayBackfillRun();

    // Both tenants are repaired — a brand-scoped migration would have silently
    // fixed at most one of them.
    expectConfig($nodeA)->toBe(delayBackfillCanonical(['amount' => 5, 'unit' => 'minutes']));
    expectConfig($nodeB)->toBe(delayBackfillCanonical(['amount' => 9, 'unit' => 'minutes']));

    // brand_id is untouched, and isolation still holds through the model.
    expect((int) DB::table('automation_nodes')->where('id', $nodeA)->value('brand_id'))->toBe((int) $brandA);
    expect((int) DB::table('automation_nodes')->where('id', $nodeB)->value('brand_id'))->toBe((int) $brandB);

    BrandContext::setCurrent($brandA);
    expect(AutomationNode::find($nodeB))->toBeNull();
    expect(AutomationNode::find($nodeA))->not->toBeNull();
});
