<?php

// 2.23.0: connection operations send a raw body, their own headers and any
// RFC 7230 method, and read JSON, XML or text answers, with the response
// headers in the output.

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationConnectionOperation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Goldnead\StatamicAutomations\Support\XmlToArray;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const RAW_SECRET = 'caldav-app-password-91xq';

beforeEach(function () {
    app(HostGuard::class)->resolveUsing(fn () => ['93.184.216.34']);
});

function rawConnection(): AutomationConnection
{
    return AutomationConnection::create([
        'handle' => 'dav',
        'name' => 'DAV',
        'base_url' => 'https://dav.example.test/cal/',
        'auth_type' => 'basic',
        'auth_config' => ['username' => 'adrian@example.test', 'password' => RAW_SECRET],
    ]);
}

function rawOperation(AutomationConnection $connection, array $overrides = []): AutomationConnectionOperation
{
    return $connection->operations()->create(array_merge([
        'handle' => 'query',
        'name' => 'Query',
        'method' => 'REPORT',
        'path' => '/',
        'body_mode' => 'raw',
        'content_type' => 'application/xml; charset=utf-8',
        'raw_body' => '<q start="{{ input.start | xml }}">{{ input.note }}</q>',
        'headers' => ['Depth' => '{{ input.depth }}'],
        'inputs' => [
            ['handle' => 'start', 'type' => 'text', 'required' => true],
            ['handle' => 'note', 'type' => 'text'],
            ['handle' => 'depth', 'type' => 'text', 'default' => '1'],
        ],
    ], $overrides));
}

function runRawOperation(string $type, array $config, bool $testMode = false): AutomationNodeRun
{
    $automation = Automation::create(['name' => 'Raw', 'handle' => 'raw_'.uniqid()]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual', 'config' => []]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'op', 'type' => $type, 'config' => $config]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'op']);

    $automation = $automation->fresh()->load(['nodes', 'edges']);
    $context = AutomationContext::make([], testMode: $testMode);

    $runner = app(WorkflowRunner::class);
    $run = $runner->execute($runner->createRun($automation, $context, $automation->nodes->first()), $context);

    return $run->nodeRuns()->where('node_key', 'op')->firstOrFail();
}

it('sends a raw body with its content type, operation headers and any method', function () {
    Http::fake(['dav.example.test/*' => Http::response('<ok/>', 207, ['Content-Type' => 'application/xml'])]);
    rawOperation(rawConnection());

    $nodeRun = runRawOperation('connection.dav.query', ['start' => 'a"<b', 'note' => 'x & y']);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_SUCCESS);
    expect($nodeRun->output['status'])->toBe(207);

    Http::assertSent(fn (Request $r) => $r->method() === 'REPORT'
        && $r->url() === 'https://dav.example.test/cal/'
        && $r->body() === '<q start="a&quot;&lt;b">x & y</q>'
        && $r->header('Content-Type') === ['application/xml; charset=utf-8']
        && $r->header('Depth') === ['1']
        && $r->header('Authorization') === ['Basic '.base64_encode('adrian@example.test:'.RAW_SECRET)]);
});

it('inserts a value as a json literal with the json filter', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    rawOperation(rawConnection(), [
        'method' => 'POST',
        'content_type' => 'application/json',
        'raw_body' => '{"text": {{ input.start | json }}, "n": {{ input.note | json }}}',
        'headers' => [],
    ]);

    runRawOperation('connection.dav.query', ['start' => 'say "hi"', 'note' => '']);

    Http::assertSent(fn (Request $r) => $r->body() === '{"text": "say \"hi\"", "n": null}'
        && json_decode($r->body(), true) === ['text' => 'say "hi"', 'n' => null]);
});

it('lets the credential win over an operation header of the same name', function () {
    Http::fake(['*' => Http::response('', 200)]);
    rawOperation(rawConnection(), ['headers' => ['Authorization' => 'Bearer stolen']]);

    runRawOperation('connection.dav.query', ['start' => 'x']);

    Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Basic '.base64_encode('adrian@example.test:'.RAW_SECRET)]);
});

it('leaves an existing json operation exactly as it was', function () {
    Http::fake(['*' => Http::response(['id' => 7], 200, ['Content-Type' => 'application/json'])]);
    $connection = rawConnection();
    $connection->operations()->create([
        'handle' => 'legacy',
        'name' => 'Legacy',
        'method' => 'POST',
        'path' => '/items',
        'body' => ['title' => '{{ input.title }}'],
        'inputs' => [['handle' => 'title', 'type' => 'text']],
        'response_map' => ['id' => 'id'],
    ]);

    $nodeRun = runRawOperation('connection.dav.legacy', ['title' => 'Hi']);

    expect($nodeRun->output['id'])->toBe(7);
    expect($nodeRun->output['body'])->toBe(['id' => 7]);
    Http::assertSent(fn (Request $r) => $r->data() === ['title' => 'Hi']
        && str_starts_with($r->header('Content-Type')[0], 'application/json'));
});

it('previews the raw body in test mode and sends nothing', function () {
    Http::fake();
    rawOperation(rawConnection());

    $nodeRun = runRawOperation('connection.dav.query', ['start' => 's', 'note' => 'n'], testMode: true);

    expect($nodeRun->output['preview']['method'])->toBe('REPORT');
    expect($nodeRun->output['preview']['body'])->toBe('<q start="s">n</q>');
    expect($nodeRun->output['preview']['content_type'])->toBe('application/xml; charset=utf-8');
    expect($nodeRun->output['preview']['headers'])->toContain('Depth', 'Authorization');
    Http::assertNothingSent();
});

it('reads an xml answer without namespaces so response_map paths work', function () {
    $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
        .'<d:response><d:href>/cal/a.ics</d:href><d:propstat><d:prop><d:getetag>"e1"</d:getetag></d:prop></d:propstat></d:response>'
        .'<d:response><d:href>/cal/b.ics</d:href><d:propstat><d:prop><d:getetag>"e2"</d:getetag>'
        .'<c:calendar-data><![CDATA[BEGIN:VCALENDAR]]></c:calendar-data></d:prop></d:propstat></d:response>'
        .'</d:multistatus>';
    Http::fake(['*' => Http::response($xml, 207, ['Content-Type' => 'text/xml; charset=utf-8', 'ETag' => '"coll"'])]);
    rawOperation(rawConnection(), ['response_map' => [
        'first_href' => 'multistatus.response.0.href',
        'second_etag' => 'multistatus.response.1.propstat.prop.getetag',
        'ics' => 'multistatus.response.1.propstat.prop.calendar-data',
    ]]);

    $nodeRun = runRawOperation('connection.dav.query', ['start' => 'x']);

    expect($nodeRun->output['first_href'])->toBe('/cal/a.ics');
    expect($nodeRun->output['second_etag'])->toBe('"e2"');
    expect($nodeRun->output['ics'])->toBe('BEGIN:VCALENDAR');
    expect($nodeRun->output['headers']['etag'])->toBe('"coll"');
    expect($nodeRun->output['headers']['content-type'])->toBe('text/xml; charset=utf-8');
});

it('reads xml when told to even if the content type says otherwise, and text as text', function (string $format, mixed $expected) {
    Http::fake(['*' => Http::response('<a><b>1</b></a>', 200, ['Content-Type' => 'text/plain'])]);
    rawOperation(rawConnection(), ['response_format' => $format, 'response_map' => ['b' => 'a.b']]);

    $nodeRun = runRawOperation('connection.dav.query', ['start' => 'x']);

    expect($nodeRun->output['b'])->toBe($expected);
})->with([
    'xml' => ['xml', '1'],
    'text' => ['text', null],
    'auto' => ['auto', null],
]);

it('masks the credential in response headers and drops set-cookie', function () {
    Http::fake(['*' => Http::response('', 200, ['X-Echo' => RAW_SECRET, 'Set-Cookie' => 'session=abc'])]);
    rawOperation(rawConnection());

    $nodeRun = runRawOperation('connection.dav.query', ['start' => 'x']);

    expect($nodeRun->output['headers']['x-echo'])->toBe('••••');
    expect($nodeRun->output['headers'])->not->toHaveKey('set-cookie');
    expect(json_encode($nodeRun->output))->not->toContain(RAW_SECRET);
});

it('refuses xml with a doctype and xml that is not well formed', function () {
    expect(XmlToArray::parse('<!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>'))->toBeNull();
    expect(XmlToArray::parse('<a><b></a>'))->toBeNull();
    expect(XmlToArray::parse(''))->toBeNull();
    expect(XmlToArray::parse('<a id="1"><b>x</b><b>y</b>tail</a>'))->toBe(['a' => ['@id' => '1', 'b' => ['x', 'y'], '#text' => 'tail']]);
});

it('accepts any rfc 7230 method on save, uppercased, and refuses anything else', function () {
    $this->actingAsSuperUser();
    $connection = rawConnection();
    $url = cp_route('statamic-automations.api.connections.operations.store', $connection->id);

    $this->postJson($url, [
        'handle' => 'find', 'name' => 'Find', 'method' => 'propfind', 'path' => '/',
        'body_mode' => 'raw', 'content_type' => 'application/xml', 'raw_body' => '<x/>',
        'headers' => ['Depth' => '0'], 'response_format' => 'xml',
    ])->assertCreated()
        ->assertJsonPath('data.method', 'PROPFIND')
        ->assertJsonPath('data.body_mode', 'raw')
        ->assertJsonPath('data.raw_body', '<x/>')
        ->assertJsonPath('data.headers', ['Depth' => '0'])
        ->assertJsonPath('data.response_format', 'xml');

    $this->postJson($url, ['handle' => 'bad', 'name' => 'Bad', 'method' => 'GET X', 'path' => '/'])
        ->assertJsonValidationErrors('method');
    $this->postJson($url, ['handle' => 'bad2', 'name' => 'Bad', 'method' => 'GET', 'path' => '/', 'content_type' => "text/plain\r\nX-Evil: 1"])
        ->assertJsonValidationErrors('content_type');
    $this->postJson($url, ['handle' => 'bad3', 'name' => 'Bad', 'method' => 'GET', 'path' => '/', 'response_format' => 'yaml'])
        ->assertJsonValidationErrors('response_format');
});

it('presents an operation stored before 2.23 with the defaults', function () {
    $this->actingAsSuperUser();
    $connection = rawConnection();
    $connection->operations()->create(['handle' => 'old', 'name' => 'Old', 'method' => 'GET', 'path' => '/']);

    $this->getJson(cp_route('statamic-automations.api.connections.show', $connection->id))
        ->assertOk()
        ->assertJsonPath('data.operations.0.body_mode', 'form_json')
        ->assertJsonPath('data.operations.0.response_format', 'auto')
        ->assertJsonPath('data.operations.0.headers', []);
});
