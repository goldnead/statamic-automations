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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;

const SECRET = 'sk-live-7f3a9c1e5b';

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
