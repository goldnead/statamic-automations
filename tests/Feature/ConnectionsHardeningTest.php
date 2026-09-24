<?php

// Review findings on 5c4c360: SSRF, redirects, path traversal, masking,
// query merging, caching, brand isolation. Every test here names the host it
// resolves; none depends on real DNS.

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Http\Controllers\ConnectionsController;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationConnectionOperation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Goldnead\StatamicAutomations\Support\UnsafeHostException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

const HARDENING_SECRET = 'sk-live-7f3a9c1e5b';

beforeEach(function () {
    // Every name resolves to a public address unless a test says otherwise.
    $this->dns = ['rebind.example' => ['127.0.0.1'], 'intranet.example' => ['192.168.1.10']];
    app(HostGuard::class)->resolveUsing(fn (string $host) => $this->dns[$host] ?? ['93.184.216.34']);
});

function hardeningConnection(array $overrides = []): AutomationConnection
{
    return AutomationConnection::create(array_merge([
        'handle' => 'svc',
        'name' => 'Service',
        'base_url' => 'https://api.example.test/v1/',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => HARDENING_SECRET],
    ], $overrides));
}

function hardeningOperation(AutomationConnection $connection, array $overrides = []): AutomationConnectionOperation
{
    return $connection->operations()->create(array_merge([
        'handle' => 'op',
        'name' => 'Op',
        'method' => 'GET',
        'path' => '/items/{{ input.id }}',
        'inputs' => [['handle' => 'id', 'label' => 'Id', 'type' => 'text', 'required' => true]],
    ], $overrides));
}

function hardeningRun(string $type, array $config, bool $testMode = false): AutomationNodeRun
{
    $automation = Automation::create(['name' => 'Op', 'handle' => 'op_'.uniqid()]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual', 'config' => []]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'op', 'type' => $type, 'config' => $config]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'op']);

    $automation = $automation->fresh()->load(['nodes', 'edges']);
    $context = AutomationContext::make([], testMode: $testMode);

    $runner = app(WorkflowRunner::class);
    $run = $runner->execute($runner->createRun($automation, $context, $automation->nodes->first()), $context);

    return $run->nodeRuns()->where('node_key', 'op')->firstOrFail();
}

// ── 1. SSRF ────────────────────────────────────────────────────────────────

it('refuses private, loopback, link-local and reserved addresses', function (string $ip) {
    expect(fn () => app(HostGuard::class)->guard("http://{$ip}/"))->toThrow(UnsafeHostException::class);
})->with([
    '127.0.0.1', '127.8.8.8', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.0.1',
    '169.254.169.254', '0.0.0.0', '[::1]', '[fc00::1]', '[fd12:3456::1]', '[fe80::1]',
    '[::ffff:127.0.0.1]', '[::ffff:10.0.0.1]', '[::]',
]);

it('lets public addresses through', function (string $ip) {
    expect(app(HostGuard::class)->guard("https://{$ip}/"))->toBeArray();
})->with(['8.8.8.8', '172.32.0.1', '[2606:4700::1111]']);

it('refuses a hostname that resolves to a private address, and one that does not resolve', function () {
    $this->dns['nowhere.example'] = [];

    expect(fn () => app(HostGuard::class)->guard('https://intranet.example/'))->toThrow(UnsafeHostException::class);
    expect(fn () => app(HostGuard::class)->guard('https://nowhere.example/'))->toThrow(UnsafeHostException::class);
});

it('refuses non-http schemes and private hosts when a connection is saved', function (string $url) {
    $this->actingAsSuperUser();

    $this->postJson(cp_route('statamic-automations.api.connections.store'), [
        'handle' => 'svc', 'name' => 'Service', 'base_url' => $url, 'auth_type' => 'none',
    ])->assertStatus(422)->assertJsonValidationErrors('base_url');
})->with([
    'ftp://api.example.test/', 'file:///etc/passwd', 'gopher://api.example.test/',
    'http://127.0.0.1/', 'http://10.0.0.5/', 'http://[::1]/', 'https://intranet.example/', 'https://rebind.example/',
]);

it('accepts a public host when a connection is saved', function () {
    $this->actingAsSuperUser();

    $this->postJson(cp_route('statamic-automations.api.connections.store'), [
        'handle' => 'svc', 'name' => 'Service', 'base_url' => 'https://api.example.test/', 'auth_type' => 'none',
    ])->assertCreated();
});

it('checks the host again right before an operation is called', function () {
    Http::fake();
    // Saved while public, resolves to loopback by the time it runs.
    hardeningOperation(hardeningConnection(['base_url' => 'https://rebind.example/']));

    $nodeRun = hardeningRun('connection.svc.op', ['id' => '1']);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED);
    expect($nodeRun->error_message)->toContain('rebind.example');
    Http::assertNothingSent();
});

it('checks the host again right before the test button calls it', function () {
    Http::fake();
    $this->actingAsSuperUser();
    $connection = hardeningConnection(['base_url' => 'https://rebind.example/']);

    $response = $this->postJson(cp_route('statamic-automations.api.connections.test', $connection))->assertOk();

    expect($response->json('ok'))->toBeFalse();
    Http::assertNothingSent();
});

it('allows private hosts when the config says so', function () {
    config()->set('automations.connections.allow_private_hosts', true);
    Http::fake(['*' => Http::response([], 200)]);
    hardeningOperation(hardeningConnection(['base_url' => 'http://127.0.0.1:5678/']));

    $nodeRun = hardeningRun('connection.svc.op', ['id' => '1']);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_SUCCESS);
    Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:5678/items/1');
});

// ── 2. Redirects ───────────────────────────────────────────────────────────

it('does not follow a redirect, so a header credential never reaches another host', function () {
    Http::fake([
        'api.example.test/*' => Http::response('', 302, ['Location' => 'https://evil.example/steal']),
        'evil.example/*' => Http::response([], 200),
    ]);
    hardeningOperation(hardeningConnection([
        'auth_type' => 'header',
        'auth_config' => ['name' => 'X-Api-Key', 'value' => HARDENING_SECRET],
    ]));

    $nodeRun = hardeningRun('connection.svc.op', ['id' => '1']);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED);
    expect($nodeRun->output['status'])->toBe(302);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
});

it('does not follow a redirect from the test button either', function () {
    Http::fake([
        'api.example.test/*' => Http::response('', 302, ['Location' => 'https://evil.example/steal']),
        'evil.example/*' => Http::response([], 200),
    ]);
    $this->actingAsSuperUser();
    $connection = hardeningConnection();

    $response = $this->postJson(cp_route('statamic-automations.api.connections.test', $connection))->assertOk();

    expect($response->json('ok'))->toBeFalse();
    expect($response->json('status'))->toBe(302);
    Http::assertSentCount(1);
});

// ── 3. Path traversal ──────────────────────────────────────────────────────

it('refuses dot segments in a path input', function (string $value) {
    Http::fake();
    hardeningOperation(hardeningConnection());

    $nodeRun = hardeningRun('connection.svc.op', ['id' => $value]);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED);
    expect($nodeRun->error_message)->toContain('id');
    Http::assertNothingSent();
})->with(['..', '.', '%2e%2e', '%2E%2E', '%252e%252e', '../admin', '..\\admin']);

// ── 4. Masking ─────────────────────────────────────────────────────────────

it('shows the name of a header credential in the preview', function () {
    hardeningOperation(hardeningConnection([
        'auth_type' => 'header',
        'auth_config' => ['name' => 'X-Api-Key', 'value' => HARDENING_SECRET],
    ]));

    $nodeRun = hardeningRun('connection.svc.op', ['id' => '1'], testMode: true);

    expect($nodeRun->output['preview']['headers'])->toContain('X-Api-Key');
    expect(json_encode($nodeRun->output))->not->toContain(HARDENING_SECRET);
});

it('does not mangle response values with a short basic username', function () {
    Http::fake(['*' => Http::response(['country' => 'canada'], 200)]);
    hardeningOperation(hardeningConnection([
        'auth_type' => 'basic',
        'auth_config' => ['username' => 'ada', 'password' => HARDENING_SECRET],
    ]), ['response_map' => ['country' => 'country']]);

    $nodeRun = hardeningRun('connection.svc.op', ['id' => '1']);

    expect($nodeRun->output['country'])->toBe('canada');
});

// ── 5. Query merging ───────────────────────────────────────────────────────

it('keeps a query already present in the base url and the path', function () {
    Http::fake(['*' => Http::response([], 200)]);
    hardeningOperation(
        hardeningConnection(['base_url' => 'https://api.example.test/v1?api-version=2']),
        ['path' => '/items/{{ input.id }}?expand=1', 'query' => ['notify' => 'no']],
    );

    hardeningRun('connection.svc.op', ['id' => '7']);
    $preview = hardeningRun('connection.svc.op', ['id' => '7'], testMode: true);

    $expected = 'https://api.example.test/v1/items/7?api-version=2&expand=1&notify=no';
    Http::assertSent(fn (Request $r) => $r->url() === $expected);
    expect($preview->output['preview']['url'])->toBe($expected);
});

// ── 6. Caching ─────────────────────────────────────────────────────────────

it('looks an operation up once per handle, not once per registry call', function () {
    hardeningOperation(hardeningConnection());
    $registry = app(NodeRegistry::class);
    $registry->has('connection.svc.op');

    DB::enableQueryLog();
    $registry->class('connection.svc.op');
    $registry->kind('connection.svc.op');
    $registry->describe('connection.svc.op');
    $registry->outputSpec('connection.svc.op');

    expect(DB::getQueryLog())->toBe([]);
});

it('sees an operation that was edited after it was first looked up', function () {
    $operation = hardeningOperation(hardeningConnection());
    $registry = app(NodeRegistry::class);
    expect($registry->describe('connection.svc.op')['label'])->toBe('Op');

    $operation->update(['name' => 'Renamed']);

    expect($registry->describe('connection.svc.op')['label'])->toBe('Renamed');
});

// ── 7. Brand isolation, placeholders, before migrate ──────────────────────

it('keeps a connection of brand A out of brand B', function () {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    $brandA = DB::table('brands')->insertGetId(['handle' => 'brand-a', 'name' => 'A', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
    $brandB = DB::table('brands')->insertGetId(['handle' => 'brand-b', 'name' => 'B', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
    Http::fake();

    BrandContext::runFor($brandA, fn () => hardeningOperation(hardeningConnection()));
    // Warm the lookup in A first, so a cache keyed by handle alone would leak.
    BrandContext::runFor($brandA, fn () => app(NodeRegistry::class)->describe('connection.svc.op'));

    BrandContext::runFor($brandB, function () {
        $listed = collect(app(NodeRegistry::class)->sourced())->pluck('handle');
        expect($listed)->not->toContain('connection.svc.op');
        expect(app(NodeRegistry::class)->describe('connection.svc.op')['label'])->not->toBe('Op');
        expect(AutomationConnection::query()->count())->toBe(0);

        $nodeRun = hardeningRun('connection.svc.op', ['id' => '1']);
        expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED);
    });

    Http::assertNothingSent();
});

it('keeps the stored secret when an update sends the placeholder or nothing', function (mixed $sent) {
    $this->actingAsSuperUser();
    $connection = hardeningConnection();

    $this->patchJson(cp_route('statamic-automations.api.connections.update', $connection), [
        'handle' => 'svc', 'name' => 'Service', 'base_url' => 'https://api.example.test/v1/',
        'auth_type' => 'bearer', 'auth_config' => ['token' => $sent],
    ])->assertOk();

    expect($connection->fresh()->auth_config)->toBe(['token' => HARDENING_SECRET]);
})->with([ConnectionsController::PLACEHOLDER, '', null]);

it('lists nodes and resolves handles while the connection tables are missing', function () {
    Schema::drop('automation_connection_operations');
    Schema::drop('automation_connections');
    AutomationConnectionOperation::flushCache();

    $registry = app(NodeRegistry::class);

    expect(collect($registry->all())->pluck('handle'))->toContain('send_email');
    expect($registry->has('connection.svc.op'))->toBeFalse();
});
