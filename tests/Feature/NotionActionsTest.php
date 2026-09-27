<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Integrations\Notion\NotionData;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Notion, read only (2.23.0). The payloads are shaped like the answers of the
 * anders-band.de gig sheets ("Sheets Konzerte", "Zeitplan"), API version
 * 2025-09-03: rollups over relations, a date with a `time_zone` and one
 * without, callouts whose heading is a quote.
 */

const NOTION_TOKEN = 'ntn_4471fa0c9e2b8d3f';
const SHEETS = '38d739f3-6bad-80d9-89c0-000b01954fb3';
const ZEITPLAN = '38d739f3-6bad-8050-8e9d-000b7623a322';
const BEVERN = '3c8739f3-6bad-809f-90eb-d3762307e5a1';
const KALENDER = '3c8739f3-6bad-8011-a2b3-0c4d5e6f7a8b';
const VENUE = '2f1739f3-6bad-80aa-bb00-11aa22bb33cc';
const CALLOUT = '4d0739f3-6bad-80cc-9e00-aa11bb22cc33';

beforeEach(function () {
    Sleep::fake();

    AutomationConnection::create([
        'handle' => 'notion',
        'name' => 'Notion',
        'base_url' => 'https://api.notion.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => NOTION_TOKEN],
    ]);
});

function notionRt(string $text): array
{
    return [[
        'type' => 'text',
        'text' => ['content' => $text, 'link' => null],
        'annotations' => ['bold' => false, 'italic' => false, 'strikethrough' => false, 'underline' => false, 'code' => false, 'color' => 'default'],
        'plain_text' => $text,
        'href' => null,
    ]];
}

function notionPage(string $id, array $properties, array $extra = []): array
{
    return [
        'object' => 'page',
        'id' => $id,
        'created_time' => '2026-08-26T11:39:00.000Z',
        'last_edited_time' => '2026-09-25T08:12:00.000Z',
        'parent' => ['type' => 'data_source_id', 'data_source_id' => SHEETS],
        'archived' => false,
        'in_trash' => false,
        'properties' => $properties,
        'url' => 'https://www.notion.so/'.str_replace('-', '', $id),
        'public_url' => null,
        ...$extra,
    ];
}

function bevernSheet(): array
{
    return notionPage(BEVERN, [
        'Name' => ['id' => 'title', 'type' => 'title', 'title' => notionRt('Sheet Bevern | Schlosskapelle (2026)')],
        'Konzertkalender' => ['id' => 'a%3Bb', 'type' => 'relation', 'relation' => [['id' => KALENDER]], 'has_more' => false],
        'Venue' => ['id' => 'Vn%40', 'type' => 'rollup', 'rollup' => ['type' => 'array', 'function' => 'show_original', 'array' => [
            ['type' => 'relation', 'relation' => [['id' => VENUE]], 'has_more' => false],
        ]]],
        'Programm' => ['id' => 'Pr%3F', 'type' => 'rollup', 'rollup' => ['type' => 'array', 'function' => 'show_original', 'array' => [
            ['type' => 'rich_text', 'rich_text' => notionRt('Weihnachtsprogramm')],
            ['type' => 'rich_text', 'rich_text' => []],
        ]]],
        'Gage' => ['id' => 'G%3D', 'type' => 'number', 'number' => 1800],
        'Status' => ['id' => 'St', 'type' => 'status', 'status' => ['id' => 's1', 'name' => 'Fix', 'color' => 'green']],
        'Besetzung' => ['id' => 'Bs', 'type' => 'multi_select', 'multi_select' => [['id' => 'm1', 'name' => 'Band', 'color' => 'blue'], ['id' => 'm2', 'name' => 'Chor', 'color' => 'red']]],
        'Bestätigt' => ['id' => 'Bt', 'type' => 'checkbox', 'checkbox' => true],
        'Kontakt' => ['id' => 'Kt', 'type' => 'phone_number', 'phone_number' => '+49 5531 12345'],
        'Mail' => ['id' => 'Ml', 'type' => 'email', 'email' => 'kapelle@example.org'],
        'Link' => ['id' => 'Lk', 'type' => 'url', 'url' => null],
        'Time' => ['id' => 'Tm', 'type' => 'formula', 'formula' => ['type' => 'string', 'string' => '13:00']],
        'Anzahl' => ['id' => 'Az', 'type' => 'rollup', 'rollup' => ['type' => 'number', 'number' => 4, 'function' => 'count']],
        'Nummer' => ['id' => 'Nr', 'type' => 'unique_id', 'unique_id' => ['prefix' => 'GIG', 'number' => 37]],
        'Datum' => ['id' => 'Dt', 'type' => 'date', 'date' => ['start' => '2026-12-12', 'end' => null, 'time_zone' => null]],
    ]);
}

function zeitplanEntry(string $id, string $title, array $date): array
{
    return notionPage($id, [
        'Name' => ['id' => 'title', 'type' => 'title', 'title' => notionRt($title)],
        'Konzertkalender' => ['id' => 'kk', 'type' => 'relation', 'relation' => [['id' => KALENDER]], 'has_more' => false],
        'Date' => ['id' => 'dt', 'type' => 'date', 'date' => $date],
        // Notion's own formula, off by the UTC offset; never used.
        'Time' => ['id' => 'tm', 'type' => 'formula', 'formula' => ['type' => 'string', 'string' => '13:00']],
    ]);
}

function notionList(array $results, bool $hasMore = false, ?string $next = null): array
{
    return ['object' => 'list', 'results' => $results, 'next_cursor' => $next, 'has_more' => $hasMore, 'type' => 'page_or_data_source', 'page_or_data_source' => (object) []];
}

function notionBlock(string $id, string $type, string $text, bool $hasChildren = false, array $extra = []): array
{
    return [
        'object' => 'block',
        'id' => $id,
        'parent' => ['type' => 'page_id', 'page_id' => BEVERN],
        'has_children' => $hasChildren,
        'archived' => false,
        'in_trash' => false,
        'type' => $type,
        $type => ['rich_text' => $text === '' ? [] : notionRt($text), 'color' => 'default', ...$extra],
    ];
}

function runNotion(string $type, array $config, bool $test = false)
{
    return app(NodeExecutor::class)->execute(
        new AutomationNode(['node_key' => 'n', 'type' => $type, 'config' => $config]),
        AutomationContext::make([], $test),
    );
}

// --- registration --------------------------------------------------------

it('registers the three read nodes under Notion, all allowed in a test run', function () {
    foreach (['notion.query_data_source', 'notion.get_pages', 'notion.page_text'] as $handle) {
        $entry = app(NodeRegistry::class)->get($handle);

        expect($entry)->not->toBeNull()
            ->and($entry['class']::group())->toBe('Notion')
            ->and($entry['class']::supportsTestMode())->toBeTrue();
    }
});

// --- query ---------------------------------------------------------------

it('queries a data source with the token, the pinned version and flattened properties', function () {
    Http::fake(['api.notion.com/v1/data_sources/*' => Http::response(notionList([bevernSheet()]))]);

    $result = runNotion('notion.query_data_source', ['data_source_id' => str_replace('-', '', SHEETS), 'time_zone' => 'Europe/Berlin']);

    expect($result->isSuccess())->toBeTrue((string) $result->error);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.notion.com/v1/data_sources/'.SHEETS.'/query'
        && $r->method() === 'POST'
        && $r->header('Authorization') === ['Bearer '.NOTION_TOKEN]
        && $r->header('Notion-Version') === ['2025-09-03']
        && $r['page_size'] === 100);

    $page = $result->output['pages'][0];

    expect($result->output['count'])->toBe(1)
        ->and($result->output['has_more'])->toBeFalse()
        ->and($page['id'])->toBe(BEVERN)
        ->and($page['url'])->toBe('https://www.notion.so/'.str_replace('-', '', BEVERN))
        ->and($page['title'])->toBe('Sheet Bevern | Schlosskapelle (2026)')
        ->and($page['last_edited_time'])->toBe('2026-09-25T08:12:00.000Z')
        ->and($page['properties'])->toMatchArray([
            'Name' => 'Sheet Bevern | Schlosskapelle (2026)',
            'Konzertkalender' => [KALENDER],
            'Venue' => [VENUE],
            'Programm' => ['Weihnachtsprogramm'],
            'Gage' => 1800,
            'Status' => 'Fix',
            'Besetzung' => ['Band', 'Chor'],
            'Bestätigt' => true,
            'Kontakt' => '+49 5531 12345',
            'Mail' => 'kapelle@example.org',
            'Link' => null,
            'Time' => '13:00',
            'Anzahl' => 4,
            'Nummer' => 'GIG-37',
        ])
        ->and($page['properties']['Datum'])->toMatchArray(['start' => '2026-12-12', 'end' => null, 'has_time' => false, 'start_date' => '2026-12-12', 'start_time' => null]);

    expect(json_encode($result->output))->not->toContain(NOTION_TOKEN);
});

it('reads a timetable in Berlin time, with and without time_zone on the date, never from the Time formula', function () {
    Http::fake(['api.notion.com/v1/data_sources/*' => Http::response(notionList([
        // With time_zone: local time in that zone, no offset in the string.
        zeitplanEntry('11111111-6bad-80d9-89c0-000b01954fb3', 'Get In', ['start' => '2026-12-12T15:00:00.000', 'end' => null, 'time_zone' => 'Europe/Berlin']),
        // Without: the offset is in the string (UTC here).
        zeitplanEntry('22222222-6bad-80d9-89c0-000b01954fb3', 'Soundcheck', ['start' => '2026-12-12T15:30:00.000+00:00', 'end' => '2026-12-12T16:15:00.000+00:00', 'time_zone' => null]),
        zeitplanEntry('33333333-6bad-80d9-89c0-000b01954fb3', 'Abbau', ['start' => '2026-12-13T00:30:00.000', 'end' => null, 'time_zone' => 'Europe/Berlin']),
    ]))]);

    $result = runNotion('notion.query_data_source', ['data_source_id' => ZEITPLAN, 'time_zone' => 'Europe/Berlin']);
    $dates = array_map(fn ($p) => $p['properties']['Date'], $result->output['pages']);

    expect($dates[0])->toMatchArray(['start' => '2026-12-12T15:00:00+01:00', 'time_zone' => 'Europe/Berlin', 'has_time' => true, 'start_date' => '2026-12-12', 'start_time' => '15:00'])
        ->and($dates[1])->toMatchArray(['start' => '2026-12-12T15:30:00+00:00', 'end' => '2026-12-12T16:15:00+00:00', 'start_time' => '16:30', 'end_time' => '17:15'])
        ->and($dates[2])->toMatchArray(['start_date' => '2026-12-13', 'start_time' => '00:30']);
});

it('builds the "related to any" filter, combined with a raw filter, and passes sorts', function () {
    Http::fake(['api.notion.com/*' => Http::response(notionList([]))]);

    $result = runNotion('notion.query_data_source', [
        'data_source_id' => ZEITPLAN,
        'relation_property' => 'Konzertkalender',
        'relation_ids' => [KALENDER, 'https://www.notion.so/Kalender-2-'.str_replace('-', '', VENUE).'?pvs=4', KALENDER],
        'filter' => '{"property": "Status", "status": {"equals": "Fix"}}',
        'sorts' => '[{"property": "Date", "direction": "ascending"}]',
    ]);

    expect($result->isSuccess())->toBeTrue((string) $result->error);

    Http::assertSent(fn (Request $r) => $r['filter'] === ['and' => [
        ['property' => 'Status', 'status' => ['equals' => 'Fix']],
        ['or' => [
            ['property' => 'Konzertkalender', 'relation' => ['contains' => KALENDER]],
            ['property' => 'Konzertkalender', 'relation' => ['contains' => VENUE]],
        ]],
    ]] && $r['sorts'] === [['property' => 'Date', 'direction' => 'ascending']]);
});

it('reads no rows and asks nothing when the related-to list is empty', function () {
    Http::fake();

    $result = runNotion('notion.query_data_source', ['data_source_id' => ZEITPLAN, 'relation_property' => 'Konzertkalender', 'relation_ids' => []]);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['pages'])->toBe([])
        ->and($result->output['count'])->toBe(0);
    Http::assertNothingSent();
});

it('follows next_cursor and stops at the request cap with has_more', function () {
    Http::fake(['api.notion.com/*' => Http::sequence()
        ->push(notionList([bevernSheet()], true, 'cursor-2'))
        ->push(notionList([bevernSheet()], true, 'cursor-3'))
        ->push(notionList([bevernSheet()], false))]);

    $capped = runNotion('notion.query_data_source', ['data_source_id' => SHEETS, 'max_pages' => 2]);

    expect($capped->output['count'])->toBe(2)
        ->and($capped->output['has_more'])->toBeTrue();
    Http::assertSent(fn (Request $r) => ($r['start_cursor'] ?? null) === 'cursor-2');
    Http::assertSentCount(2);
});

it('fails on invalid filter JSON instead of reading every row', function () {
    Http::fake();

    $result = runNotion('notion.query_data_source', ['data_source_id' => SHEETS, 'filter' => '{"property": ']);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain('filter');
    Http::assertNothingSent();
});

it('fails with Notion\'s own message and without the token when Notion refuses', function () {
    Http::fake(['api.notion.com/*' => Http::response([
        'object' => 'error', 'status' => 404, 'code' => 'object_not_found',
        'message' => 'Could not find data_source with ID: '.SHEETS.'. Make sure the relevant pages and databases are shared with your integration.',
    ], 404)]);

    $result = runNotion('notion.query_data_source', ['data_source_id' => SHEETS]);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain('object_not_found')->toContain('shared with your integration')
        ->and((string) $result->error)->not->toContain(NOTION_TOKEN);
});

it('retries a rate limit before giving up', function () {
    Http::fake(['api.notion.com/*' => Http::sequence()
        ->push(['object' => 'error', 'code' => 'rate_limited', 'message' => 'slow down'], 429)
        ->push(notionList([bevernSheet()]))]);

    $result = runNotion('notion.query_data_source', ['data_source_id' => SHEETS]);

    expect($result->isSuccess())->toBeTrue((string) $result->error)
        ->and($result->output['count'])->toBe(1);
    Http::assertSentCount(2);
});

it('fails on a result list it does not know instead of reading it as empty', function () {
    Http::fake(['api.notion.com/*' => Http::response(['object' => 'list', 'rows' => []])]);

    expect(runNotion('notion.query_data_source', ['data_source_id' => SHEETS])->isFailed())->toBeTrue();
});

// --- without a connection ------------------------------------------------

it('reads nothing and says so without a connection', function () {
    Http::fake();
    AutomationConnection::query()->delete();

    foreach ([
        'notion.query_data_source' => ['data_source_id' => SHEETS],
        'notion.get_pages' => ['ids' => [BEVERN]],
        'notion.page_text' => ['page_id' => BEVERN],
    ] as $type => $config) {
        $result = runNotion($type, $config);

        expect($result->isFailed())->toBeTrue()
            ->and((string) $result->error)->toContain("no connection 'notion'");
    }

    Http::assertNothingSent();
});

it('reads nothing with a connection that has no token', function () {
    Http::fake();
    AutomationConnection::query()->first()->update(['auth_config' => ['token' => '']]);

    expect((string) runNotion('notion.get_pages', ['ids' => [BEVERN]])->error)->toContain('has no token');
    Http::assertNothingSent();

    AutomationConnection::query()->first()->update(['auth_type' => 'none']);

    expect((string) runNotion('notion.get_pages', ['ids' => [BEVERN]])->error)->toContain("uses 'none' auth");
    Http::assertNothingSent();
});

it('refuses a connection whose auth is not bearer, so a foreign credential never goes to Notion', function (string $type, array $config) {
    Http::fake();
    AutomationConnection::query()->first()->update(['auth_type' => $type, 'auth_config' => $config]);

    $result = runNotion('notion.get_pages', ['ids' => [BEVERN]]);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain("uses '{$type}' auth")->toContain('bearer');
    Http::assertNothingSent();
})->with([
    'basic' => ['basic', ['username' => 'someone@example.org', 'password' => 'hunter2hunter2']],
    'custom header' => ['header', ['name' => 'X-Api-Key', 'value' => 'sk-live-other-service']],
]);

it('sends no default header of the connection to Notion', function () {
    AutomationConnection::query()->first()->update(['default_headers' => ['X-Api-Key' => 'sk-live-other-service']]);
    Http::fake(['api.notion.com/*' => Http::response(bevernSheet())]);

    runNotion('notion.get_pages', ['ids' => [BEVERN]]);

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('X-Api-Key') && $r->header('Authorization') === ['Bearer '.NOTION_TOKEN]);
});

it('caps max_pages at 50 requests', function () {
    Http::fake(['api.notion.com/*' => Http::response(notionList([bevernSheet()], true, 'more'))]);

    $result = runNotion('notion.query_data_source', ['data_source_id' => SHEETS, 'max_pages' => 500]);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['count'])->toBe(50)
        ->and($result->output['has_more'])->toBeTrue();
    Http::assertSentCount(50);
});

it('fails on a block with more than 1000 children instead of returning part of the page', function () {
    Http::fake(['api.notion.com/*' => Http::response(notionList([
        notionBlock('e0000000-0000-0000-0000-000000000001', 'paragraph', 'Zeile'),
    ], true, 'more'))]);

    $result = runNotion('notion.page_text', ['page_id' => BEVERN]);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain('more than 1000 children');
    Http::assertSentCount(10);
});

it('stops a page text read after 300 requests in all', function () {
    // Every block has children, every level answers with 100 of them: depth 3
    // would need 1 + 100 + 10000 requests.
    $blocks = array_map(fn (int $i) => notionBlock(sprintf('f0000000-0000-0000-0000-%012d', $i), 'toggle', "T{$i}", true), range(1, 100));
    Http::fake(['api.notion.com/*' => Http::response(notionList($blocks))]);

    $result = runNotion('notion.page_text', ['page_id' => BEVERN, 'depth' => '3']);

    expect($result->isFailed())->toBeTrue()
        ->and((string) $result->error)->toContain('300 requests');
    Http::assertSentCount(300);
});

it('uses another connection by handle and still only calls api.notion.com', function () {
    AutomationConnection::create([
        'handle' => 'notion_anders', 'name' => 'Notion ANDERS', 'base_url' => 'https://evil.example.test',
        'auth_type' => 'bearer', 'auth_config' => ['token' => 'ntn_other'],
    ]);
    Http::fake(['api.notion.com/*' => Http::response(bevernSheet())]);

    runNotion('notion.get_pages', ['connection' => 'notion_anders', 'ids' => BEVERN]);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.notion.com/v1/pages/') && $r->header('Authorization') === ['Bearer ntn_other']);
});

it('reads for real in a test run, since it only reads', function () {
    Http::fake(['api.notion.com/*' => Http::response(notionList([bevernSheet()]))]);

    $result = runNotion('notion.query_data_source', ['data_source_id' => SHEETS], test: true);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['count'])->toBe(1);
});

it('refuses a time zone that does not exist', function () {
    Http::fake();

    expect((string) runNotion('notion.query_data_source', ['data_source_id' => SHEETS, 'time_zone' => 'Europe/Bevern'])->error)
        ->toContain('not a time zone');
});

// --- get pages -----------------------------------------------------------

it('gets the pages behind a rollup, one request each, in order and without duplicates', function () {
    $venue = notionPage(VENUE, [
        'Name' => ['id' => 'title', 'type' => 'title', 'title' => notionRt('Schlosskapelle Bevern')],
        'Adresse' => ['id' => 'ad', 'type' => 'formula', 'formula' => ['type' => 'string', 'string' => "Schlosskapelle\n Schloss 1\n37639 Bevern"]],
        'Telefon' => ['id' => 'tl', 'type' => 'phone_number', 'phone_number' => '05531 994010'],
    ]);

    Http::fake([
        'api.notion.com/v1/pages/'.VENUE => Http::response($venue),
        'api.notion.com/v1/pages/'.BEVERN => Http::response(bevernSheet()),
    ]);

    $result = runNotion('notion.get_pages', ['ids' => [VENUE, str_replace('-', '', BEVERN), VENUE]]);

    expect($result->isSuccess())->toBeTrue((string) $result->error)
        ->and(array_column($result->output['pages'], 'id'))->toBe([VENUE, BEVERN])
        ->and($result->output['pages'][0]['properties']['Adresse'])->toBe("Schlosskapelle\n Schloss 1\n37639 Bevern")
        ->and($result->output['pages'][0]['properties']['Telefon'])->toBe('05531 994010');
    Http::assertSentCount(2);
});

it('fails on a page that is not shared, or lists it as missing when told to skip', function () {
    Http::fake([
        'api.notion.com/v1/pages/'.VENUE => Http::response(['object' => 'error', 'status' => 404, 'code' => 'object_not_found', 'message' => 'Could not find page.'], 404),
        'api.notion.com/v1/pages/'.BEVERN => Http::response(bevernSheet()),
    ]);

    $strict = runNotion('notion.get_pages', ['ids' => [BEVERN, VENUE]]);
    $lenient = runNotion('notion.get_pages', ['ids' => [BEVERN, VENUE], 'skip_missing' => true]);

    expect($strict->isFailed())->toBeTrue()
        ->and($lenient->isSuccess())->toBeTrue()
        ->and($lenient->output['count'])->toBe(1)
        ->and($lenient->output['missing'])->toBe([VENUE]);
});

it('reads nothing for an empty ID list', function () {
    Http::fake();

    $result = runNotion('notion.get_pages', ['ids' => '']);

    expect($result->isSuccess())->toBeTrue()->and($result->output['pages'])->toBe([]);
    Http::assertNothingSent();
});

// --- page text -----------------------------------------------------------

it('reads the callouts of a sheet with their quote heading, and drops empty template lines', function () {
    Http::fake([
        'api.notion.com/v1/blocks/'.BEVERN.'/children*' => Http::response(notionList([
            notionBlock('b0000000-0000-0000-0000-000000000001', 'heading_2', 'Infos'),
            notionBlock(CALLOUT, 'callout', '', true, ['icon' => ['type' => 'emoji', 'emoji' => '🏨']]),
            notionBlock('b0000000-0000-0000-0000-000000000002', 'paragraph', ''),
            ['object' => 'block', 'id' => 'b0000000-0000-0000-0000-000000000003', 'type' => 'image', 'has_children' => false, 'image' => ['type' => 'external', 'external' => ['url' => 'https://example.org/x.png']]],
            notionBlock('b0000000-0000-0000-0000-000000000004', 'to_do', 'Merch einpacken', false, ['checked' => true]),
        ])),
        'api.notion.com/v1/blocks/'.CALLOUT.'/children*' => Http::response(notionList([
            notionBlock('c0000000-0000-0000-0000-000000000001', 'quote', 'Hotel'),
            notionBlock('c0000000-0000-0000-0000-000000000002', 'paragraph', 'Zimmer gebucht: 3 DZ'),
            notionBlock('c0000000-0000-0000-0000-000000000003', 'paragraph', 'Schlüssel: '),
            notionBlock('c0000000-0000-0000-0000-000000000004', 'paragraph', ''),
            notionBlock('c0000000-0000-0000-0000-000000000005', 'bulleted_list_item', 'Frühstück ab 7'),
        ])),
    ]);

    $result = runNotion('notion.page_text', ['page_id' => 'https://www.notion.so/Sheet-Bevern-'.str_replace('-', '', BEVERN).'?pvs=4', 'skip_empty_labels' => true]);

    expect($result->isSuccess())->toBeTrue((string) $result->error);

    $blocks = $result->output['blocks'];

    expect(array_column($blocks, 'type'))->toBe(['heading_2', 'callout', 'to_do'])
        ->and($blocks[1]['heading'])->toBe('Hotel')
        ->and(array_column($blocks[1]['body'], 'text'))->toBe(['Zimmer gebucht: 3 DZ', 'Frühstück ab 7'])
        ->and($blocks[2]['checked'])->toBeTrue()
        ->and($result->output['plain'])->toBe("Infos\nHotel\nZimmer gebucht: 3 DZ\n• Frühstück ab 7\n☑ Merch einpacken");
});

it('keeps empty template labels unless told to drop them, and reads no children at depth 1', function () {
    Http::fake([
        'api.notion.com/v1/blocks/'.BEVERN.'/children*' => Http::response(notionList([
            notionBlock(CALLOUT, 'callout', 'Technik-Infos', true),
            notionBlock('b0000000-0000-0000-0000-000000000005', 'paragraph', 'Backline: '),
        ])),
        'api.notion.com/v1/blocks/'.CALLOUT.'/children*' => Http::response(notionList([
            notionBlock('c0000000-0000-0000-0000-000000000009', 'paragraph', 'PA vor Ort'),
        ])),
    ]);

    $flat = runNotion('notion.page_text', ['page_id' => BEVERN, 'depth' => '1']);

    expect($flat->output['plain'])->toBe("Technik-Infos\nBackline:");
    Http::assertSentCount(1);
});

it('reads the children of columns in their place', function () {
    Http::fake([
        'api.notion.com/v1/blocks/'.BEVERN.'/children*' => Http::response(notionList([
            ['object' => 'block', 'id' => 'd0000000-0000-0000-0000-000000000001', 'type' => 'column_list', 'has_children' => true, 'column_list' => (object) []],
        ])),
        'api.notion.com/v1/blocks/d0000000-0000-0000-0000-000000000001/children*' => Http::response(notionList([
            ['object' => 'block', 'id' => 'd0000000-0000-0000-0000-000000000002', 'type' => 'column', 'has_children' => true, 'column' => (object) []],
        ])),
        'api.notion.com/v1/blocks/d0000000-0000-0000-0000-000000000002/children*' => Http::response(notionList([
            notionBlock('d0000000-0000-0000-0000-000000000003', 'paragraph', 'Links in der Spalte'),
        ])),
    ]);

    expect(runNotion('notion.page_text', ['page_id' => BEVERN])->output['plain'])->toBe('Links in der Spalte');
});

it('fails without a page ID', function () {
    expect(runNotion('notion.page_text', ['page_id' => 'kein-link'])->isFailed())->toBeTrue();
});

// --- the pieces together -------------------------------------------------

/*
 * The gig calendar of anders-band.de, rebuilt on the canvas: the sheets, per
 * sheet its timetable (related through Konzertkalender), then one text. A
 * timetable over two days puts the date in front of each line; one over a
 * single day shows only the time.
 */
it('builds the gig text of the custom sync on the canvas', function () {
    $sheet = bevernSheet();
    Http::fake([
        'api.notion.com/v1/data_sources/'.SHEETS.'/query' => Http::response(notionList([$sheet])),
        'api.notion.com/v1/data_sources/'.ZEITPLAN.'/query' => Http::response(notionList([
            zeitplanEntry('11111111-6bad-80d9-89c0-000b01954fb3', 'Get In', ['start' => '2026-12-12T15:00:00.000', 'end' => null, 'time_zone' => 'Europe/Berlin']),
            zeitplanEntry('22222222-6bad-80d9-89c0-000b01954fb3', 'Soundcheck', ['start' => '2026-12-12T15:30:00.000+00:00', 'end' => '2026-12-12T16:15:00.000+00:00', 'time_zone' => null]),
            zeitplanEntry('33333333-6bad-80d9-89c0-000b01954fb3', 'Abbau', ['start' => '2026-12-13T00:30:00.000', 'end' => null, 'time_zone' => 'Europe/Berlin']),
        ])),
    ]);

    $template = <<<'ANTLERS'
{{ item.title }}

Zeitplan{{ days = nodes.zeitplan.pages | pluck('properties.Date.start_date') | unique | count }}
{{ nodes.zeitplan.pages }}{{ if days > 1 }}{{ properties.Date.start_date | format('d.m.') }} {{ /if }}{{ properties.Date.start_time }}{{ if properties.Date.end_time }}–{{ properties.Date.end_time }}{{ /if }} {{ title }}
{{ /nodes.zeitplan.pages }}

{{ if item.properties.Programm }}
Programm
{{ item.properties.Programm | join("\n") }}
{{ /if }}

Notion: {{ item.url }}
ANTLERS;

    $automation = Automation::create(['name' => 'Gigs', 'handle' => 'gigs-'.uniqid()]);
    $nodes = [
        ['t', 'manual', []],
        ['sheets', 'notion.query_data_source', ['data_source_id' => SHEETS, 'time_zone' => 'Europe/Berlin']],
        ['each', 'loop', ['items' => '{{ nodes.sheets.pages }}']],
        ['zeitplan', 'notion.query_data_source', [
            'data_source_id' => ZEITPLAN, 'time_zone' => 'Europe/Berlin',
            'relation_property' => 'Konzertkalender', 'relation_ids' => '{{ item.properties.Konzertkalender }}',
            'sorts' => '[{"property": "Date", "direction": "ascending"}]',
        ]],
        ['text', 'compose_text', ['template' => $template]],
    ];
    foreach ($nodes as [$key, $type, $config]) {
        AutomationNode::create(['automation_id' => $automation->id, 'node_key' => $key, 'type' => $type, 'config' => $config]);
    }
    foreach ([['t', 'sheets', 'default'], ['sheets', 'each', 'default'], ['each', 'zeitplan', 'loop'], ['zeitplan', 'text', 'default']] as [$from, $to, $output]) {
        AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => $from, 'from_output' => $output, 'to_node_key' => $to]);
    }
    $automation = $automation->fresh()->load(['nodes', 'edges']);

    $context = AutomationContext::make();
    $runner = app(WorkflowRunner::class);
    $run = $runner->execute($runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't')), $context);

    $text = $run->nodeRuns()->where('node_key', 'text')->first()?->output['text'];

    expect($run->status)->toBe(AutomationRun::STATUS_SUCCESS, (string) $run->error_message)
        ->and($text)->toBe(
            "Sheet Bevern | Schlosskapelle (2026)\n\n"
            ."Zeitplan\n12.12. 15:00 Get In\n12.12. 16:30–17:15 Soundcheck\n13.12. 00:30 Abbau\n\n"
            ."Programm\nWeihnachtsprogramm\n\n"
            .'Notion: https://www.notion.so/'.str_replace('-', '', BEVERN),
        );

    Http::assertSent(fn (Request $r) => str_contains($r->url(), ZEITPLAN)
        && $r['filter'] === ['property' => 'Konzertkalender', 'relation' => ['contains' => KALENDER]]);
});

// --- ids -----------------------------------------------------------------

it('reads page IDs from IDs, links, lists, JSON and text', function () {
    $hex = str_replace('-', '', BEVERN);

    expect(NotionData::id($hex))->toBe(BEVERN)
        ->and(NotionData::id('https://app.notion.com/p/anders-band/Sheet-Bevern-2026-'.$hex.'?source=copy_link'))->toBe(BEVERN)
        ->and(NotionData::id('nope'))->toBeNull()
        ->and(NotionData::ids("{$hex}, ".VENUE))->toBe([BEVERN, VENUE])
        ->and(NotionData::ids(json_encode([BEVERN, [VENUE]])))->toBe([BEVERN, VENUE])
        ->and(NotionData::ids(null))->toBe([]);
});
