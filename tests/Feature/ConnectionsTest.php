<?php

// Spec: GoldnerOS TASKS/automations-connections-spec-2026-09-24.md
// Connections hold a service's base URL and credentials; each operation on a
// connection becomes its own action node, handle connection.<conn>.<op>.

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationConnection;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationNodeRun;
use Goldnead\StatamicAutomations\Support\HostGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;

const SECRET = 'sk-live-7f3a9c1e5b';

// `api.example.test` never resolves, and the host guard refuses a name that
// does not; answer with a public address instead of asking real DNS.
beforeEach(function () {
    app(HostGuard::class)->resolveUsing(fn () => ['93.184.216.34']);
});

function makeConnection(array $overrides = []): AutomationConnection
{
    return AutomationConnection::create(array_merge([
        'handle' => 'slack',
        'name' => 'Slack',
        'base_url' => 'https://api.example.test/v1/',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => SECRET],
    ], $overrides));
}

function makeOperation(AutomationConnection $connection, array $overrides = [])
{
    return $connection->operations()->create(array_merge([
        'handle' => 'post_message',
        'name' => 'Post message',
        'method' => 'POST',
        'path' => '/channels/{{ input.channel }}/messages',
        'query' => ['notify' => '{{ input.notify }}'],
        'body' => ['text' => '{{ input.text }}'],
        'inputs' => [
            ['handle' => 'channel', 'label' => 'Channel', 'type' => 'text', 'required' => true],
            ['handle' => 'text', 'label' => 'Text', 'type' => 'textarea', 'required' => true],
            ['handle' => 'notify', 'label' => 'Notify', 'type' => 'text', 'required' => false, 'default' => 'no'],
        ],
        'response_map' => ['ts' => 'message.ts'],
    ], $overrides));
}

/** Runs manual trigger → the operation node through the real engine. */
function runOperation(string $type, array $config, bool $testMode = false): AutomationNodeRun
{
    $automation = Automation::create(['name' => 'Op', 'handle' => 'op_'.uniqid()]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual', 'config' => []]);
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'op', 'type' => $type, 'config' => $config]);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'op']);

    $automation = $automation->fresh()->load(['nodes', 'edges']);
    $context = AutomationContext::make(['form' => ['name' => 'Ada']], testMode: $testMode);

    $runner = app(WorkflowRunner::class);
    $run = $runner->execute($runner->createRun($automation, $context, $automation->nodes->first()), $context);

    return $run->nodeRuns()->where('node_key', 'op')->firstOrFail();
}

it('stores credentials encrypted at rest', function () {
    $connection = makeConnection();

    $raw = DB::table('automation_connections')->where('id', $connection->id)->value('auth_config');

    expect($raw)->not->toContain(SECRET);
    expect($connection->fresh()->auth_config)->toBe(['token' => SECRET]);
});

it('lists each operation as its own action node', function () {
    $this->actingAsSuperUser();
    makeOperation(makeConnection());

    $actions = collect($this->getJson(cp_route('statamic-automations.api.actions.index'))->assertOk()->json());
    $node = $actions->flatten(1)->firstWhere('handle', 'connection.slack.post_message')
        ?? $actions->firstWhere('handle', 'connection.slack.post_message');

    expect($node)->not->toBeNull();
    expect($node['label'])->toBe('Post message');
    expect($node['group'])->toBe('Slack');
    expect(collect($node['schema'])->pluck('handle')->all())->toBe(['channel', 'text', 'notify']);
    expect(array_keys($node['output_schema']))->toContain('status', 'ts');
});

it('calls the service with url, query, body, auth and maps the response', function () {
    Http::fake(['api.example.test/*' => Http::response(['message' => ['ts' => '123.45']], 200)]);
    makeOperation(makeConnection());

    $nodeRun = runOperation('connection.slack.post_message', [
        'channel' => 'general team',
        'text' => 'Hi {{ form.name }}',
    ]);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_SUCCESS);
    expect($nodeRun->output['status'])->toBe(200);
    expect($nodeRun->output['ts'])->toBe('123.45');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.example.test/v1/channels/general%20team/messages?notify=no'
        && $r->header('Authorization') === ['Bearer '.SECRET]
        && $r->data() === ['text' => 'Hi Ada']);
});

it('applies basic and custom header auth', function (string $type, array $config, string $header, string $expected) {
    Http::fake(['*' => Http::response([], 200)]);
    $connection = makeConnection(['handle' => 'svc', 'auth_type' => $type, 'auth_config' => $config]);
    makeOperation($connection, ['handle' => 'ping', 'method' => 'GET', 'path' => '/ping', 'query' => [], 'body' => [], 'inputs' => []]);

    runOperation('connection.svc.ping', []);

    Http::assertSent(fn (Request $r) => $r->header($header) === [$expected]);
})->with([
    'basic' => ['basic', ['username' => 'ada', 'password' => SECRET], 'Authorization', 'Basic '.base64_encode('ada:'.SECRET)],
    'header' => ['header', ['name' => 'X-Api-Key', 'value' => SECRET], 'X-Api-Key', SECRET],
]);

it('keeps the json body intact when an input contains quotes', function () {
    Http::fake(['*' => Http::response([], 200)]);
    makeOperation(makeConnection());

    runOperation('connection.slack.post_message', ['channel' => 'c', 'text' => 'say "hi", \\ and }']);

    Http::assertSent(fn (Request $r) => $r->data() === ['text' => 'say "hi", \\ and }']);
});

it('fails on a non-2xx status unless told otherwise', function () {
    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);
    $connection = makeConnection();
    makeOperation($connection);
    makeOperation($connection, ['handle' => 'lenient', 'fail_on_error_status' => false]);

    $strict = runOperation('connection.slack.post_message', ['channel' => 'c', 'text' => 't']);
    $lenient = runOperation('connection.slack.lenient', ['channel' => 'c', 'text' => 't']);

    expect($strict->status)->toBe(AutomationNodeRun::STATUS_FAILED);
    expect($lenient->status)->toBe(AutomationNodeRun::STATUS_SUCCESS);
    expect($lenient->output['status'])->toBe(500);
});

it('fails cleanly when a required input is missing', function () {
    Http::fake();
    makeOperation(makeConnection());

    $nodeRun = runOperation('connection.slack.post_message', ['channel' => 'c']);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED);
    expect($nodeRun->error_message)->toContain('text');
    Http::assertNothingSent();
});

it('sends nothing in test mode', function () {
    Http::fake();
    makeOperation(makeConnection());

    $nodeRun = runOperation('connection.slack.post_message', ['channel' => 'c', 'text' => 't'], testMode: true);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_SUCCESS);
    expect($nodeRun->output)->toHaveKey('preview');
    Http::assertNothingSent();
});

it('never persists the credential, even when the service echoes it', function () {
    Http::fake(['*' => fn (Request $r) => Http::response(['echo' => $r->header('Authorization')[0]], 200)]);
    makeOperation(makeConnection(), ['response_map' => ['echo' => 'echo']]);

    $nodeRun = runOperation('connection.slack.post_message', ['channel' => 'c', 'text' => 't']);

    expect(json_encode($nodeRun->input))->not->toContain(SECRET);
    expect(json_encode($nodeRun->output))->not->toContain(SECRET);
    expect(json_encode($nodeRun->run->context))->not->toContain(SECRET);
});

it('fails cleanly when the operation was deleted', function () {
    Http::fake();
    $operation = makeOperation(makeConnection());
    $operation->delete();

    $nodeRun = runOperation('connection.slack.post_message', ['channel' => 'c', 'text' => 't']);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED);
    expect($nodeRun->error_message)->toContain('connection.slack.post_message');
});

it('tests a connection from the cp and requires the permission', function () {
    Http::fake(['api.example.test/*' => Http::response('ok', 200)]);
    $connection = makeConnection(['test_path' => '/auth.test']);

    $regular = User::make()->email('regular@example.com');
    $regular->save();
    $denied = $this->actingAs($regular)->postJson(cp_route('statamic-automations.api.connections.test', $connection));
    expect($denied->getStatusCode())->toBeIn([401, 403]);

    $this->actingAsSuperUser();
    $response = $this->postJson(cp_route('statamic-automations.api.connections.test', $connection))->assertOk();

    expect($response->json('ok'))->toBeTrue();
    expect($response->json('status'))->toBe(200);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.example.test/v1/auth.test'
        && $r->header('Authorization') === ['Bearer '.SECRET]);
});

it('never sends credentials back to the cp', function () {
    $this->actingAsSuperUser();
    $connection = makeConnection();

    $index = $this->getJson(cp_route('statamic-automations.api.connections.index'))->assertOk();
    $show = $this->getJson(cp_route('statamic-automations.api.connections.show', $connection))->assertOk();

    expect($index->getContent())->not->toContain(SECRET);
    expect($show->getContent())->not->toContain(SECRET);
});

it('tests the unsaved form values when they are sent, and saves nothing', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->actingAsSuperUser();
    $connection = makeConnection(['test_path' => '/auth.test']);

    $this->postJson(cp_route('statamic-automations.api.connections.test', $connection), [
        'base_url' => 'https://api.example.test/v2',
        'test_path' => '/me',
        'auth_type' => 'bearer',
        // Empty means "the stored token", as on save.
        'auth_config' => ['token' => ''],
        'default_headers' => ['X-Trace' => 'on'],
    ])->assertOk()->assertJson(['ok' => true, 'status' => 200]);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.example.test/v2/me'
        && $r->header('Authorization') === ['Bearer '.SECRET]
        && $r->header('X-Trace') === ['on']);

    expect($connection->fresh()->base_url)->toBe('https://api.example.test/v1/')
        ->and($connection->fresh()->test_path)->toBe('/auth.test');
});

it('refuses a submitted private base url in the test without sending anything', function () {
    Http::fake();
    $this->actingAsSuperUser();
    $connection = makeConnection();

    // The guard resolves every name to a public address in this file; a
    // literal private address never reaches the resolver.
    $response = $this->postJson(cp_route('statamic-automations.api.connections.test', $connection), [
        'base_url' => 'http://169.254.169.254/latest',
    ])->assertOk();

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('error'))->toContain('private or reserved address');
    Http::assertNothingSent();
});

it('does not send a stored secret to a host that was only typed into the test', function () {
    Http::fake(['*' => Http::response('ok', 200)]);
    $this->actingAsSuperUser();
    $connection = makeConnection();

    $this->postJson(cp_route('statamic-automations.api.connections.test', $connection), [
        'base_url' => 'https://elsewhere.example.test',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => ''],
    ])->assertOk();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://elsewhere.example.test'));
    Http::assertNotSent(fn (Request $r) => str_contains(implode(',', $r->header('Authorization')), SECRET));
});

// ─── OAuth2 client credentials ──────────────────────────────────────────────

const OAUTH_SECRET = 'client-secret-9d2f81';
const TOKEN_URL = 'https://auth.example.test/oauth/token';

function makeOAuthConnection(array $auth = [], array $overrides = []): AutomationConnection
{
    $connection = AutomationConnection::create(array_merge([
        'handle' => 'zoom',
        'name' => 'Zoom',
        'base_url' => 'https://api.example.test/v2',
        'auth_type' => 'oauth2_client_credentials',
        'auth_config' => array_merge([
            'token_url' => TOKEN_URL,
            'client_id' => 'client-id-1',
            'client_secret' => OAUTH_SECRET,
            'scope' => '',
            'client_auth_method' => 'client_secret_basic',
            'token_request_fields' => [],
        ], $auth),
    ], $overrides));

    $connection->operations()->create([
        'handle' => 'ping', 'name' => 'Ping', 'method' => 'GET', 'path' => '/ping',
        'query' => [], 'body' => [], 'inputs' => [], 'response_map' => ['echo' => 'echo'],
    ]);

    return $connection;
}

function tokenResponse(string $token, int $expiresIn = 3600)
{
    return Http::response(['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => $expiresIn], 200);
}

function tokenRequests(): \Illuminate\Support\Collection
{
    return Http::recorded()->filter(fn ($pair) => $pair[0]->url() === TOKEN_URL)->map(fn ($pair) => $pair[0])->values();
}

function apiRequests(): \Illuminate\Support\Collection
{
    return Http::recorded()->filter(fn ($pair) => str_starts_with($pair[0]->url(), 'https://api.example.test'))->map(fn ($pair) => $pair[0])->values();
}

afterEach(function () {
    \Illuminate\Support\Carbon::setTestNow();
});

beforeEach(function () {
    \Illuminate\Support\Facades\Cache::flush();
});

it('fetches a token on the first call and serves the second from the cache', function () {
    Http::fake([
        TOKEN_URL => Http::sequence()->push(['access_token' => 'tok-1', 'expires_in' => 3600])->push(['access_token' => 'tok-2', 'expires_in' => 3600]),
        'api.example.test/*' => Http::response(['ok' => true], 200),
    ]);
    makeOAuthConnection();

    $first = runOperation('connection.zoom.ping', []);
    $second = runOperation('connection.zoom.ping', []);

    expect($first->status)->toBe(AutomationNodeRun::STATUS_SUCCESS)
        ->and($second->status)->toBe(AutomationNodeRun::STATUS_SUCCESS)
        ->and(tokenRequests())->toHaveCount(1)
        ->and(apiRequests())->toHaveCount(2);

    expect(apiRequests()->every(fn (Request $r) => $r->header('Authorization') === ['Bearer tok-1']))->toBeTrue();
});

it('keeps the cached token encrypted', function () {
    Http::fake([TOKEN_URL => tokenResponse('tok-cached-123'), 'api.example.test/*' => Http::response([], 200)]);
    $connection = makeOAuthConnection();

    runOperation('connection.zoom.ping', []);

    $keys = \Illuminate\Support\Facades\Cache::get('statamic-automations:oauth2:'.$connection->id.':'.hash('sha256', json_encode($connection->fresh()->auth_config)));
    expect($keys)->toBeString()->not->toContain('tok-cached-123');
});

it('renews a token that has expired', function () {
    Http::fake([
        TOKEN_URL => Http::sequence()->push(['access_token' => 'tok-1', 'expires_in' => 3600])->push(['access_token' => 'tok-2', 'expires_in' => 3600]),
        'api.example.test/*' => Http::response([], 200),
    ]);
    makeOAuthConnection();

    runOperation('connection.zoom.ping', []);
    // Past expires_in minus the safety margin.
    \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(3600 - 59));
    runOperation('connection.zoom.ping', []);

    expect(tokenRequests())->toHaveCount(2);
    expect(apiRequests()->last()->header('Authorization'))->toBe(['Bearer tok-2']);
});

it('drops the token on a 401 and repeats the request exactly once with a new one', function () {
    Http::fake([
        TOKEN_URL => Http::sequence()->push(['access_token' => 'tok-1', 'expires_in' => 3600])->push(['access_token' => 'tok-2', 'expires_in' => 3600]),
        'api.example.test/*' => Http::sequence()->push(['error' => 'expired'], 401)->push(['echo' => 'fine'], 200),
    ]);
    makeOAuthConnection();

    $nodeRun = runOperation('connection.zoom.ping', []);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_SUCCESS)
        ->and($nodeRun->output['echo'])->toBe('fine')
        ->and(tokenRequests())->toHaveCount(2)
        ->and(apiRequests())->toHaveCount(2);
    expect(apiRequests()[0]->header('Authorization'))->toBe(['Bearer tok-1']);
    expect(apiRequests()[1]->header('Authorization'))->toBe(['Bearer tok-2']);
});

it('fails clearly on a second 401 and does not retry again', function () {
    Http::fake([
        TOKEN_URL => Http::sequence()->push(['access_token' => 'tok-1', 'expires_in' => 3600])->push(['access_token' => 'tok-2', 'expires_in' => 3600]),
        'api.example.test/*' => Http::response(['error' => 'nope'], 401),
    ]);
    // Lenient operations still fail on an authentication that cannot be repaired.
    $connection = makeOAuthConnection();
    $connection->operations()->update(['fail_on_error_status' => false]);

    $nodeRun = runOperation('connection.zoom.ping', []);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED)
        ->and($nodeRun->error_message)->toContain('401')->toContain('renewed')
        ->and(tokenRequests())->toHaveCount(2)
        ->and(apiRequests())->toHaveCount(2);
});

it('does not retry a 401 on a connection with a fixed key', function () {
    Http::fake(['*' => Http::response([], 401)]);
    makeOperation(makeConnection(), ['handle' => 'ping', 'method' => 'GET', 'path' => '/ping', 'query' => [], 'body' => [], 'inputs' => []]);

    runOperation('connection.slack.ping', []);

    Http::assertSentCount(1);
});

it('sends the client credentials as the connection says', function (string $method) {
    Http::fake([TOKEN_URL => tokenResponse('tok-1'), 'api.example.test/*' => Http::response([], 200)]);
    makeOAuthConnection(['client_auth_method' => $method, 'scope' => 'recording:read']);

    runOperation('connection.zoom.ping', []);

    $request = tokenRequests()->first();
    expect($request->data())->toMatchArray(['grant_type' => 'client_credentials', 'scope' => 'recording:read']);

    if ($method === 'client_secret_basic') {
        expect($request->header('Authorization'))->toBe(['Basic '.base64_encode('client-id-1:'.OAUTH_SECRET)])
            ->and($request->data())->not->toHaveKey('client_secret');
    } else {
        expect($request->header('Authorization'))->toBe([])
            ->and($request->data())->toMatchArray(['client_id' => 'client-id-1', 'client_secret' => OAUTH_SECRET]);
    }
})->with(['client_secret_basic', 'client_secret_post']);

it('lets the extra token fields override grant_type and add account_id', function () {
    Http::fake([TOKEN_URL => tokenResponse('tok-1'), 'api.example.test/*' => Http::response([], 200)]);
    makeOAuthConnection(['token_request_fields' => ['grant_type' => 'account_credentials', 'account_id' => 'acc-42', 'empty' => '']]);

    runOperation('connection.zoom.ping', []);

    expect(tokenRequests()->first()->data())->toBe(['grant_type' => 'account_credentials', 'account_id' => 'acc-42']);
});

it('refuses an unsafe token url without any http call', function () {
    Http::fake();
    makeOAuthConnection(['token_url' => 'http://169.254.169.254/oauth/token']);

    $nodeRun = runOperation('connection.zoom.ping', []);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED)
        ->and($nodeRun->error_message)->toContain('private or reserved address');
    Http::assertNothingSent();
});

it('refuses an unsafe token url on save', function () {
    Http::fake();
    $this->actingAsSuperUser();

    $this->postJson(cp_route('statamic-automations.api.connections.store'), [
        'handle' => 'bad', 'name' => 'Bad', 'base_url' => 'https://api.example.test',
        'auth_type' => 'oauth2_client_credentials',
        'auth_config' => ['token_url' => 'http://169.254.169.254/token', 'client_id' => 'a', 'client_secret' => 'b'],
    ])->assertJsonValidationErrors(['auth_config.token_url']);

    expect(AutomationConnection::count())->toBe(0);
    Http::assertNothingSent();
});

it('fails with a clear message when the token endpoint refuses or answers without a token', function (mixed $response, string $expected) {
    Http::fake([TOKEN_URL => $response, 'api.example.test/*' => Http::response([], 200)]);
    makeOAuthConnection();

    $nodeRun = runOperation('connection.zoom.ping', []);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED)
        ->and($nodeRun->error_message)->toContain($expected);
    // No fallback to an unauthenticated request.
    expect(apiRequests())->toHaveCount(0);
})->with([
    'refused' => [fn () => Http::response(['error' => 'invalid_client'], 400), 'HTTP 400'],
    'no token' => [fn () => Http::response(['expires_in' => 3600], 200), 'no access_token'],
    'not json' => [fn () => Http::response('<html>', 200), 'no access_token'],
]);

it('never lets the client secret or a token reach the run log or the cp', function () {
    $this->actingAsSuperUser();
    // The service echoes everything: the Authorization header, and in the
    // token error the secret it was sent.
    Http::fake([
        TOKEN_URL => Http::sequence()
            ->push(['access_token' => 'tok-live-abc', 'expires_in' => 3600])
            ->push(['error_description' => 'bad '.OAUTH_SECRET.' tok-live-abc'], 400),
        'api.example.test/*' => fn (Request $r) => Http::response(['echo' => $r->header('Authorization')[0], 'secret' => OAUTH_SECRET], 401),
    ]);
    $connection = makeOAuthConnection();
    $connection->operations()->update(['response_map' => ['echo' => 'echo', 'secret' => 'secret']]);

    $nodeRun = runOperation('connection.zoom.ping', []);

    expect($nodeRun->status)->toBe(AutomationNodeRun::STATUS_FAILED);

    $everything = json_encode([
        $nodeRun->input, $nodeRun->output, $nodeRun->error_message, $nodeRun->run->context,
        $nodeRun->run->nodeRuns()->get()->map->only(['input', 'output', 'error_message'])->all(),
    ]);
    expect($everything)->not->toContain(OAUTH_SECRET)->not->toContain('tok-live-abc')
        ->not->toContain(base64_encode('client-id-1:'.OAUTH_SECRET));

    $index = $this->getJson(cp_route('statamic-automations.api.connections.index'))->assertOk()->getContent();
    $show = $this->getJson(cp_route('statamic-automations.api.connections.show', $connection))->assertOk()->getContent();
    expect($index.$show)->not->toContain(OAUTH_SECRET);
    expect(json_decode($show, true)['data']['auth_config'])->toMatchArray([
        'token_url' => TOKEN_URL, 'client_id' => 'client-id-1', 'client_secret' => '••••••••',
    ]);
});

it('masks the secret token fields for the cp but shows the grant type', function () {
    $this->actingAsSuperUser();
    $connection = makeOAuthConnection(['token_request_fields' => ['grant_type' => 'account_credentials', 'account_id' => 'acc-42']]);

    $response = $this->getJson(cp_route('statamic-automations.api.connections.show', $connection))->assertOk();

    expect($response->json('data.auth_config.token_request_fields'))->toBe(['grant_type' => 'account_credentials', 'account_id' => '••••••••']);
    expect($response->getContent())->not->toContain('acc-42');
});

it('keeps stored OAuth2 secrets when an update sends the placeholders', function () {
    $this->actingAsSuperUser();
    $connection = makeOAuthConnection(['token_request_fields' => ['account_id' => 'acc-42']]);

    $this->patchJson(cp_route('statamic-automations.api.connections.update', $connection), [
        'handle' => 'zoom', 'name' => 'Zoom 2', 'base_url' => 'https://api.example.test/v2',
        'auth_type' => 'oauth2_client_credentials',
        'auth_config' => [
            'token_url' => TOKEN_URL, 'client_id' => 'client-id-1', 'client_secret' => '••••••••',
            'client_auth_method' => 'client_secret_post', 'token_request_fields' => ['account_id' => '••••••••'],
        ],
    ])->assertOk();

    $stored = $connection->fresh()->auth_config;
    expect($stored['client_secret'])->toBe(OAUTH_SECRET)
        ->and($stored['token_request_fields'])->toBe(['account_id' => 'acc-42'])
        ->and($stored['client_auth_method'])->toBe('client_secret_post');
});

it('rejects an unknown client auth method and a missing client secret', function () {
    $this->actingAsSuperUser();
    $base = ['handle' => 'z', 'name' => 'Z', 'base_url' => 'https://api.example.test', 'auth_type' => 'oauth2_client_credentials'];

    $this->postJson(cp_route('statamic-automations.api.connections.store'), $base + [
        'auth_config' => ['token_url' => TOKEN_URL, 'client_id' => 'a', 'client_secret' => 'b', 'client_auth_method' => 'nope'],
    ])->assertJsonValidationErrors(['auth_config.client_auth_method']);

    $this->postJson(cp_route('statamic-automations.api.connections.store'), $base + [
        'auth_config' => ['token_url' => TOKEN_URL, 'client_id' => 'a', 'client_secret' => ''],
    ])->assertJsonValidationErrors(['auth_config.client_secret']);

    expect(AutomationConnection::count())->toBe(0);
});

it('tests an OAuth2 connection from the cp through the token service', function () {
    $this->actingAsSuperUser();
    Http::fake([TOKEN_URL => tokenResponse('tok-1'), 'api.example.test/*' => Http::response('ok', 200)]);
    $connection = makeOAuthConnection([], ['test_path' => '/users/me']);

    $this->postJson(cp_route('statamic-automations.api.connections.test', $connection))
        ->assertOk()->assertJson(['ok' => true, 'status' => 200]);

    expect(tokenRequests())->toHaveCount(1);
    expect(apiRequests()->first()->header('Authorization'))->toBe(['Bearer tok-1']);
});

it('reports a failing token endpoint in the cp test without leaking the secret', function () {
    $this->actingAsSuperUser();
    Http::fake([TOKEN_URL => Http::response(['error_description' => 'no '.OAUTH_SECRET], 400)]);
    $connection = makeOAuthConnection();

    $response = $this->postJson(cp_route('statamic-automations.api.connections.test', $connection))->assertOk();

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('error'))->toContain('HTTP 400');
    expect($response->getContent())->not->toContain(OAUTH_SECRET);
});

// ─── Connection templates ───────────────────────────────────────────────────

function storeFromTemplate(string $handle, array $auth): \Illuminate\Testing\TestResponse
{
    $template = app(\Goldnead\StatamicAutomations\Registries\ConnectionTemplateRegistry::class)->get($handle)['connection'];

    return test()->postJson(cp_route('statamic-automations.api.connections.store'), [
        'handle' => $template['handle'],
        'name' => $template['name'],
        'base_url' => $template['base_url'],
        'auth_type' => $template['auth_type'],
        'auth_config' => $auth,
        'template_handle' => $handle,
    ]);
}

it('registers the built-in connection templates', function () {
    $registry = \Goldnead\StatamicAutomations\Facades\Automations::connectionTemplates();

    expect(array_keys($registry->all()))->toContain('zoom_server_to_server', 'deepgram');

    $zoom = $registry->get('zoom_server_to_server');
    expect($zoom['connection'])->toMatchArray([
        'base_url' => 'https://api.zoom.us/v2',
        'auth_type' => 'oauth2_client_credentials',
    ]);
    expect($zoom['connection']['auth_config'])->toMatchArray([
        'token_url' => 'https://zoom.us/oauth/token',
        'client_auth_method' => 'client_secret_basic',
        'token_request_fields' => ['grant_type' => 'account_credentials', 'account_id' => ''],
    ]);
    expect(collect($zoom['operations'])->pluck('handle')->all())->toBe(['recordings_list', 'recording_get']);

    $deepgram = $registry->get('deepgram');
    expect($deepgram['connection'])->toMatchArray(['base_url' => 'https://api.deepgram.com/v1', 'auth_type' => 'header']);
    expect(collect($deepgram['operations'])->pluck('handle')->all())->toBe(['transcribe_url']);
});

it('creates the zoom connection with exactly its two operations in one go', function () {
    $this->actingAsSuperUser();

    storeFromTemplate('zoom_server_to_server', [
        'token_url' => 'https://zoom.us/oauth/token', 'client_id' => 'cid', 'client_secret' => OAUTH_SECRET,
        'client_auth_method' => 'client_secret_basic',
        'token_request_fields' => ['grant_type' => 'account_credentials', 'account_id' => 'acc-42'],
    ])->assertCreated();

    $connection = AutomationConnection::where('handle', 'zoom')->firstOrFail();
    expect($connection->operations()->pluck('handle')->all())->toBe(['recordings_list', 'recording_get']);

    $list = $connection->operations()->where('handle', 'recordings_list')->first();
    expect($list->method)->toBe('GET')
        ->and($list->path)->toBe('/users/{{ input.user_id }}/recordings')
        ->and($list->query)->toBe(['from' => '{{ input.from }}', 'to' => '{{ input.to }}'])
        ->and(collect($list->inputs)->firstWhere('handle', 'user_id')['default'])->toBe('me');

    $get = $connection->operations()->where('handle', 'recording_get')->first();
    expect($get->path)->toBe('/meetings/{{ input.meeting_id }}/recordings')
        ->and(collect($get->inputs)->firstWhere('handle', 'meeting_id')['required'])->toBeTrue();
});

it('creates the deepgram connection with its one operation, and the call matches the spec', function () {
    $this->actingAsSuperUser();
    Http::fake(['api.deepgram.com/*' => Http::response(['results' => []], 200)]);

    storeFromTemplate('deepgram', ['name' => 'Authorization', 'value' => 'Token dg-key-123456'])->assertCreated();

    $connection = AutomationConnection::where('handle', 'deepgram')->firstOrFail();
    expect($connection->operations()->pluck('handle')->all())->toBe(['transcribe_url']);

    runOperation('connection.deepgram.transcribe_url', ['url' => 'https://files.example.test/a.mp3']);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.deepgram.com/v1/listen?model=nova-3&language=de'
        && $r->header('Authorization') === ['Token dg-key-123456']
        && $r->data() === ['url' => 'https://files.example.test/a.mp3']);
});

it('leaves operations editable and unbound after the template', function () {
    $this->actingAsSuperUser();
    storeFromTemplate('deepgram', ['name' => 'Authorization', 'value' => 'Token k'])->assertCreated();
    $connection = AutomationConnection::where('handle', 'deepgram')->firstOrFail();
    $operation = $connection->operations()->first();

    $this->patchJson(cp_route('statamic-automations.api.connections.operations.update', [$connection, $operation]), [
        'handle' => 'transcribe_url', 'name' => 'Renamed', 'method' => 'POST', 'path' => '/listen',
    ])->assertOk();

    expect($operation->fresh()->name)->toBe('Renamed');
});

it('rolls the whole connection back when a template operation cannot be created', function () {
    $this->actingAsSuperUser();
    app(\Goldnead\StatamicAutomations\Registries\ConnectionTemplateRegistry::class)->register('broken', [
        'name' => 'Broken',
        'connection' => ['handle' => 'broken', 'name' => 'Broken', 'base_url' => 'https://api.example.test', 'auth_type' => 'none'],
        'operations' => [
            ['handle' => 'one', 'name' => 'One', 'method' => 'GET', 'path' => '/one'],
            // Same handle as the first: the unique rule refuses it.
            ['handle' => 'one', 'name' => 'Again', 'method' => 'GET', 'path' => '/two'],
        ],
    ]);

    $this->postJson(cp_route('statamic-automations.api.connections.store'), [
        'handle' => 'broken', 'name' => 'Broken', 'base_url' => 'https://api.example.test',
        'auth_type' => 'none', 'template_handle' => 'broken',
    ])->assertStatus(422);

    expect(AutomationConnection::count())->toBe(0)
        ->and(\Goldnead\StatamicAutomations\Models\AutomationConnectionOperation::count())->toBe(0);
});

it('refuses an unknown template instead of saving a bare connection', function () {
    $this->actingAsSuperUser();

    $this->postJson(cp_route('statamic-automations.api.connections.store'), [
        'handle' => 'x', 'name' => 'X', 'base_url' => 'https://api.example.test',
        'auth_type' => 'none', 'template_handle' => 'does_not_exist',
    ])->assertJsonValidationErrors(['template_handle']);

    expect(AutomationConnection::count())->toBe(0);
});

it('hands the create page the templates but the edit page none', function () {
    $this->actingAsSuperUser();
    $connection = makeConnection();

    $create = $this->get(cp_route('statamic-automations.connections.create'))->assertOk();
    $edit = $this->get(cp_route('statamic-automations.connections.edit', $connection))->assertOk();

    expect(collect($create->viewData('page')['props']['templates'])->pluck('handle')->all())->toContain('zoom_server_to_server', 'deepgram');
    expect($edit->viewData('page')['props']['templates'])->toBe([]);
});
