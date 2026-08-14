<?php

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The four endpoints behind the activity view: the funnel, the protocol, the
 * protocol as a file, and the people currently inside the flow.
 */
beforeEach(function (): void {
    $this->actingAsSuperUser();

    $this->automation = Automation::create(['name' => 'Series', 'handle' => 'series']);

    foreach ([['trigger', 'manual'], ['welcome', 'send_email']] as [$key, $type]) {
        $this->automation->nodes()->create([
            'node_key' => $key, 'type' => $type, 'label' => ucfirst($key),
            'position_x' => 0, 'position_y' => 0, 'config' => [],
        ]);
    }

    $this->run = function (array $attributes = []): AutomationRun {
        return AutomationRun::create(array_merge([
            'automation_id' => $this->automation->id,
            'automation_uuid' => $this->automation->uuid,
            'status' => AutomationRun::STATUS_SUCCESS,
        ], $attributes));
    };

    $this->step = function (AutomationRun $run, string $key, string $status = AutomationNodeRun::STATUS_SUCCESS): AutomationNodeRun {
        return AutomationNodeRun::create([
            'automation_run_id' => $run->id,
            'node_key' => $key,
            'node_type' => 'send_email',
            'status' => $status,
        ]);
    };

    $this->url = fn (string $suffix = '', array $query = []) => cp_route(
        'statamic-automations.api.automations.activity'.($suffix === '' ? '' : '.'.$suffix),
        $this->automation->id
    ).($query === [] ? '' : '?'.http_build_query($query));
});

// ── The funnel ───────────────────────────────────────────────────────────────

it('answers the funnel and the node numbers for a window', function (): void {
    $this->travelTo(now()->subDays(45), function (): void {
        ($this->step)(($this->run)(), 'welcome');
    });

    ($this->step)(($this->run)(), 'welcome');
    ($this->step)(($this->run)(['status' => AutomationRun::STATUS_FAILED]), 'welcome', AutomationNodeRun::STATUS_FAILED);

    $recent = $this->getJson(($this->url)('', ['range' => '30']))->assertOk()->json();

    expect($recent['range'])->toBe('30')
        ->and($recent['funnel']['enrolled'])->toBe(2)
        ->and($recent['funnel']['failed'])->toBe(1)
        ->and($recent['nodes']['welcome'])->toBe(['reached' => 2, 'completed' => 1, 'failed' => 1]);

    $everything = $this->getJson(($this->url)('', ['range' => 'all']))->assertOk()->json();

    expect($everything['funnel']['enrolled'])->toBe(3)
        ->and($everything['nodes']['welcome']['reached'])->toBe(3);
});

// ── The protocol ─────────────────────────────────────────────────────────────

it('paginates the protocol rather than truncating it', function (): void {
    // The defect this replaces is a silent `limit(200)`: the row somebody opens
    // a log to find is the one that fell off the end, with nothing on screen to
    // say a cut happened at all.
    $run = ($this->run)();

    foreach (range(1, 30) as $ignored) {
        ($this->step)($run, 'welcome');
    }

    $first = $this->getJson(($this->url)('node-runs'))->assertOk()->json();

    expect($first['data'])->toHaveCount(25)
        ->and($first['meta']['total'])->toBe(30)
        ->and($first['meta']['last_page'])->toBe(2)
        ->and($first['meta']['columns'])->not->toBeEmpty();

    $second = $this->getJson(($this->url)('node-runs', ['page' => 2]))->assertOk()->json();

    expect($second['data'])->toHaveCount(5);
});

it('filters the protocol by step, by outcome and by window', function (): void {
    $this->travelTo(now()->subDays(45), function (): void {
        ($this->step)(($this->run)(), 'welcome');
    });

    $run = ($this->run)();
    ($this->step)($run, 'trigger');
    ($this->step)($run, 'welcome');
    ($this->step)($run, 'welcome', AutomationNodeRun::STATUS_FAILED);

    $keysOf = fn (array $body) => array_column($body['data'], 'node_key');

    expect($keysOf($this->getJson(($this->url)('node-runs', ['range' => '30']))->json()))
        ->toBe(['welcome', 'welcome', 'trigger'])
        ->and($keysOf($this->getJson(($this->url)('node-runs', ['range' => '30', 'node' => 'trigger']))->json()))
        ->toBe(['trigger'])
        ->and($this->getJson(($this->url)('node-runs', ['range' => '30', 'status' => 'failed']))->json('meta.total'))
        ->toBe(1)
        // The run outside the window is not merely off page one — it is not in
        // the answer at all.
        ->and($this->getJson(($this->url)('node-runs', ['range' => '30']))->json('meta.total'))
        ->toBe(3);
});

it('names a step whose node has been deleted instead of breaking on it', function (): void {
    ($this->step)(($this->run)(), 'a-step-that-was-removed');

    $row = $this->getJson(($this->url)('node-runs'))->assertOk()->json('data.0');

    expect($row['node_label'])->toBe('a-step-that-was-removed')
        ->and($row['node_removed'])->toBeTrue();

    // And a step that is still there is labelled by its label, not its key.
    ($this->step)(($this->run)(), 'welcome');

    expect($this->getJson(($this->url)('node-runs'))->json('data.0'))
        ->toMatchArray(['node_label' => 'Welcome', 'node_removed' => false]);
});

it('leaves test runs out of the protocol', function (): void {
    ($this->step)(($this->run)(['is_test' => true]), 'welcome');
    ($this->step)(($this->run)(), 'welcome');

    expect($this->getJson(($this->url)('node-runs'))->json('meta.total'))->toBe(1);
});

it('asks the same number of queries however many rows the protocol holds', function (): void {
    $seed = function (int $runs): void {
        foreach (range(1, $runs) as $ignored) {
            $run = ($this->run)(['subject_key' => 'jane@example.com']);
            ($this->step)($run, 'trigger');
            ($this->step)($run, 'welcome');
        }
    };

    $count = function (string $url): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson($url)->assertOk();

        return $queries;
    };

    $seed(2);
    $small = $count(($this->url)('node-runs'));

    $seed(30);
    $large = $count(($this->url)('node-runs'));

    // Not "how many" — that includes whatever the CP does around the request.
    // What matters is that it does not grow with the table.
    expect($large)->toBe($small);
});

// ── The export ───────────────────────────────────────────────────────────────

it('exports exactly the rows the table shows, in the same order', function (): void {
    $run = ($this->run)(['subject_key' => 'jane@example.com']);
    ($this->step)($run, 'trigger');
    ($this->step)($run, 'welcome');
    ($this->step)($run, 'welcome', AutomationNodeRun::STATUS_FAILED);

    $filters = ['range' => '30', 'node' => 'welcome'];

    $table = $this->getJson(($this->url)('node-runs', $filters))->assertOk()->json('data');

    $response = $this->get(($this->url)('export', $filters));
    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $content = $response->streamedContent();

    // The file opens in a spreadsheet, so it carries a BOM — without it Excel
    // reads it as the machine's legacy encoding and a step called
    // "Willkommensgruß" arrives as mojibake.
    expect($content)->toStartWith("\xEF\xBB\xBF");

    $rows = array_map(
        fn (string $line) => str_getcsv($line, escape: ''),
        array_filter(explode("\n", trim(ltrim($content, "\xEF\xBB\xBF")))),
    );
    $header = array_shift($rows);

    expect($header)->toBe(['When', 'Step', 'Kind', 'Status', 'Person', 'Duration', 'Detail'])
        ->and($rows)->toHaveCount(count($table))
        ->and(array_column($rows, 1))->toBe(array_column($table, 'node_label'))
        ->and(array_column($rows, 3))->toBe(array_column($table, 'status'))
        ->and(array_column($rows, 4))->toBe(array_map(fn ($row) => (string) $row['subject'], $table));
});

it('exports every row across chunk boundaries, even when they share a timestamp', function (): void {
    // The export streams in chunks and walks by key rather than by offset, so
    // the chunk boundary is where it can lose or repeat rows. `created_at` on
    // this table is a whole second and one run writes several nodes inside it,
    // so a cursor on the timestamp alone skips the rest of a tied group — here
    // every single row is tied, which is the worst case that can occur.
    $run = ($this->run)();
    $at = now()->subDay();
    $rows = [];

    foreach (range(1, 520) as $ignored) {
        $rows[] = [
            'uuid' => (string) Str::uuid(),
            'automation_run_id' => $run->id,
            'automation_uuid' => $this->automation->uuid,
            'brand_id' => $run->brand_id,
            'is_test' => false,
            'node_key' => 'welcome',
            'node_type' => 'send_email',
            'status' => AutomationNodeRun::STATUS_SUCCESS,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    AutomationNodeRun::insert($rows);

    $csv = $this->get(($this->url)('export'))->assertOk()->streamedContent();
    $lines = array_filter(explode("\n", trim($csv)));

    expect($lines)->toHaveCount(521); // 520 rows plus the header.
});

it('refuses the export to somebody who may not read runs', function (): void {
    // Same permission as the table it exports. An export that is one URL more
    // permissive than the screen is a data leak with a download button on it.
    $role = Role::make('flow-editor')->title('Flow editor')
        ->addPermission(['access cp', 'view automations', 'edit automations']);
    Role::save($role);

    $user = User::make()->email('editor@example.com');
    $user->assignRole('flow-editor');
    $user->save();

    $this->actingAs($user);

    $this->getJson(($this->url)())->assertForbidden();
    $this->getJson(($this->url)('node-runs'))->assertForbidden();
    $this->getJson(($this->url)('subjects'))->assertForbidden();
    $this->getJson(($this->url)('export'))->assertForbidden();

    // Opened as a browser navigation — which is how the button opens it — the
    // CP does what it does for every refused screen and sends them back to
    // /cp. The point being asserted is the one that matters: no file comes out.
    $browser = $this->get(($this->url)('export'));

    $browser->assertRedirect(cp_route('index'));
    expect($browser->getStatusCode())->not->toBe(200);
});

// ── The people ───────────────────────────────────────────────────────────────

it('lists who is inside the flow and which step they are parked on', function (): void {
    $waiting = ($this->run)(['status' => AutomationRun::STATUS_WAITING, 'subject_key' => 'jane@example.com']);
    ($this->step)($waiting, 'trigger');
    ($this->step)($waiting, 'welcome');

    // Finished, so not inside the flow any more.
    ($this->step)(($this->run)(['subject_key' => 'bob@example.com']), 'welcome');

    $body = $this->getJson(($this->url)('subjects'))->assertOk()->json();

    expect($body['meta']['total'])->toBe(1)
        ->and($body['data'][0])->toMatchArray([
            'subject' => 'jane@example.com',
            'node_key' => 'welcome',
            'node_label' => 'Welcome',
            'status' => AutomationRun::STATUS_WAITING,
            'contact_url' => null,
        ]);
});

it('counts the runs that are about nobody instead of listing them as blank rows', function (): void {
    // A scheduled sweep or a webhook with no address in it is a legitimate run
    // and is not a person. Dropping it silently would make this list disagree
    // with the funnel above it for a reason nobody could see.
    ($this->run)(['status' => AutomationRun::STATUS_WAITING, 'subject_key' => 'jane@example.com']);
    ($this->run)(['status' => AutomationRun::STATUS_WAITING]);
    ($this->run)(['status' => AutomationRun::STATUS_QUEUED]);

    expect($this->getJson(($this->url)('subjects'))->json('meta.total'))->toBe(1)
        ->and($this->getJson(($this->url)())->json('without_subject'))->toBe(2);
});

it('asks the same number of queries however many people are in the flow', function (): void {
    // "Which step is this person on" is the last node run of their run, which is
    // no column anywhere. Asked per row it is a query per person.
    $seed = function (int $people): void {
        foreach (range(1, $people) as $i) {
            $run = ($this->run)([
                'status' => AutomationRun::STATUS_WAITING,
                'subject_key' => "person{$i}-".uniqid().'@example.com',
            ]);
            ($this->step)($run, 'trigger');
            ($this->step)($run, 'welcome');
        }
    };

    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson(($this->url)('subjects'))->assertOk();

        return $queries;
    };

    $seed(2);
    $small = $count();

    $seed(30);
    $large = $count();

    expect($large)->toBe($small);
});

it('does not hand the spreadsheet a formula somebody triggered with', function (): void {
    // `subject_key` comes out of the trigger context — a form field, a webhook
    // body — and the enrolment gate only trims and lowercases it. Excel and
    // LibreOffice execute a cell beginning with `=` the moment the file opens,
    // and the person opening it is the one with `view automation runs`. The
    // BOM above is what makes the spreadsheet treat this as a table at all,
    // which is what makes the route reliable rather than theoretical.
    $run = ($this->run)(['subject_key' => '=hyperlink("https://boes.example","Rechnung")']);
    ($this->step)($run, 'welcome');

    $content = $this->get(($this->url)('export', ['range' => '30']))->assertOk()->streamedContent();

    $rows = array_map(
        fn (string $line) => str_getcsv($line, escape: ''),
        array_filter(explode("\n", trim(ltrim($content, "\xEF\xBB\xBF")))),
    );

    // Still readable, no longer executable: the apostrophe is the
    // spreadsheet's own "this is text" marker and is consumed on import.
    expect($rows[1][4])->toStartWith("'")
        ->and($rows[1][4])->toContain('hyperlink');
});

it('names a step whose node is gone, so the file is not more certain than the screen', function (): void {
    // The screen carries a badge for it. Without the suffix a removed step
    // reads in the file exactly like one that still exists.
    $run = ($this->run)(['subject_key' => 'jane@example.com']);
    ($this->step)($run, 'geloeschter_knoten');

    $content = $this->get(($this->url)('export', ['range' => '30']))->assertOk()->streamedContent();

    expect($content)->toContain(__('statamic-automations::automations.activity.node_removed_suffix'));
});

it('lists somebody parked longer than the chosen window, because "in the flow" is about now', function (): void {
    // The window used to apply here too, so somebody enrolled forty days ago
    // and parked in a sixty-day wait fell out of the list at the default "last
    // 30 days" — and out of the count beside it, so nothing on the screen even
    // hinted that anybody was missing. This tab asks who is inside the flow
    // now, which is not a question about a period.
    $old = ($this->run)([
        'subject_key' => 'wartet@example.com',
        'status' => AutomationRun::STATUS_WAITING,
    ]);
    $old->forceFill(['created_at' => now()->subDays(40)])->save();

    $rows = $this->getJson(($this->url)('subjects', ['range' => '30']))->assertOk()->json('data');

    expect(collect($rows)->pluck('subject'))->toContain('wartet@example.com');
});

it('counts the runs with nobody attached the same way, whatever window is chosen', function (): void {
    $anonymous = ($this->run)(['subject_key' => null, 'status' => AutomationRun::STATUS_WAITING]);
    $anonymous->forceFill(['created_at' => now()->subDays(40)])->save();

    $overview = $this->getJson(($this->url)('', ['range' => '30']))->assertOk()->json();

    expect($overview['without_subject'])->toBe(1);
});
