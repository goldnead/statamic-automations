<?php

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AiClient;
use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Support\DispatchMode;
use Illuminate\Support\Facades\Queue;

/*
 * The AI node inside a whole run (issue #17): the long call happens in the
 * queued RunAutomation job, never in a synchronous trigger, and a JSON reply
 * reaches the next node field by field.
 */

function aiAutomation(?string $mode = null, array $ai = []): Automation
{
    $automation = Automation::create([
        'name' => 'Rückblick', 'handle' => 'rueckblick-'.bin2hex(random_bytes(4)), 'enabled' => true,
    ]);

    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 't', 'type' => 'entry_saved',
        'config' => $mode === null ? [] : [DispatchMode::CONFIG_KEY => $mode],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'review', 'type' => 'ai_generate',
        'config' => $ai + [
            'prompt' => 'Fasse zusammen: {{ entry.id }}',
            'output_mode' => 'json',
            'json_schema' => json_encode(['type' => 'object', 'required' => ['zusammenfassung'], 'properties' => [
                'zusammenfassung' => ['type' => 'string'], 'aufgaben' => ['type' => 'array'],
            ]]),
        ],
    ]);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'log', 'type' => 'add_log_entry',
        'config' => ['message' => '{{ nodes.review.json.zusammenfassung }} | {{ nodes.review.json.aufgaben }}'],
    ]);

    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 't', 'to_node_key' => 'review']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'review', 'to_node_key' => 'log']);

    return $automation->fresh();
}

function aiErrors(Automation $automation): array
{
    return collect(app(FlowValidator::class)->validate($automation->load(['nodes', 'edges'])))
        ->where('level', 'error')->pluck('code')->all();
}

it('queues the run as a RunAutomation job by default', function (): void {
    Queue::fake();
    aiAutomation();

    app(TriggerDispatcher::class)->dispatch('entry_saved', ['entry' => ['id' => '42']]);

    Queue::assertPushed(RunAutomation::class, fn ($job) => $job->connection !== 'sync');
});

it('gives the job a timeout above the longest AI node so the worker does not kill it', function (): void {
    Queue::fake();
    aiAutomation(null, ['timeout' => 600]);

    app(TriggerDispatcher::class)->dispatch('entry_saved', ['entry' => ['id' => '42']]);

    Queue::assertPushed(RunAutomation::class, fn ($job) => $job->timeout > 600);
});

it('refuses an AI node reachable from a synchronous trigger', function (): void {
    expect(aiErrors(aiAutomation('sync')))->toContain('ai_requires_queue')
        ->and(aiErrors(aiAutomation('async')))->not->toContain('ai_requires_queue')
        ->and(aiErrors(aiAutomation()))->not->toContain('ai_requires_queue');
});

it('refuses an AI node inside the body of an inline loop, whose runs add up past the job budget', function (): void {
    $automation = aiAutomation();
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'each', 'type' => 'loop', 'config' => ['items' => '{{ entry.tags }}']]);
    // t -> each -(loop)-> review -> log; the AI node now runs once per item.
    AutomationEdge::where('automation_id', $automation->id)->where('from_node_key', 't')->update(['to_node_key' => 'each']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'each', 'from_output' => 'loop', 'to_node_key' => 'review']);

    $issues = collect(app(FlowValidator::class)->validate($automation->fresh()->load(['nodes', 'edges'])));

    expect($issues->where('level', 'error')->pluck('code')->all())->toContain('ai_in_loop');
    expect($issues->firstWhere('code', 'ai_in_loop')['node_key'])->toBe('review');
});

it('allows an AI node after a loop and inside a legacy automation-mode loop', function (): void {
    $automation = aiAutomation();
    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 'each', 'type' => 'loop', 'config' => ['items' => '{{ entry.tags }}']]);
    AutomationEdge::where('automation_id', $automation->id)->where('from_node_key', 't')->update(['to_node_key' => 'each']);
    AutomationEdge::create(['automation_id' => $automation->id, 'from_node_key' => 'each', 'from_output' => 'done', 'to_node_key' => 'review']);

    expect(aiErrors($automation->fresh()))->not->toContain('ai_in_loop');

    AutomationNode::where('automation_id', $automation->id)->where('node_key', 'each')->update(['config' => ['items' => '{{ entry.tags }}', 'mode' => 'automation']]);
    AutomationEdge::where('automation_id', $automation->id)->where('from_node_key', 'each')->update(['from_output' => 'loop']);

    expect(aiErrors($automation->fresh()))->not->toContain('ai_in_loop');
});

it('flags an unusable AI timeout when validating', function (): void {
    expect(aiErrors(aiAutomation(null, ['timeout' => 'soon'])))->toContain('invalid_ai_timeout');
});

it('flags an invalid JSON schema when validating', function (string $schema): void {
    expect(aiErrors(aiAutomation(null, ['json_schema' => $schema])))->toContain('invalid_ai_schema');
})->with(['[]', '{"type":"banana"}', '{"properties":["a"]}', '{not json']);

it('hands the JSON fields of the AI node to the next node', function (): void {
    $automation = aiAutomation();
    $reply = '{"zusammenfassung":"Gut gelaufen","aufgaben":["Angebot schicken","Termin planen"]}';
    app()->instance(AiClient::class, new class($reply) implements AiClient
    {
        public function __construct(public string $reply) {}

        public function messages(array $body, int $timeout): array
        {
            return ['content' => [['type' => 'text', 'text' => $this->reply]], 'stop_reason' => 'end_turn'];
        }
    });
    config()->set('automations.ai.api_key', 'test-key');
    config()->set('automations.ai.model', 'claude-test');

    $runner = app(WorkflowRunner::class);
    $context = AutomationContext::make(['entry' => ['id' => '42']]);
    $run = $runner->execute(
        $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't')),
        $context,
    );

    $message = (string) data_get($run->nodeRuns()->where('node_key', 'log')->first()?->output, 'message');

    expect($run->status)->toBe('success')
        ->and($message)->toContain('Gut gelaufen')
        ->and($message)->toContain('Angebot schicken')
        ->and($message)->toContain('Termin planen');
});

it('fails the run at the AI node when the reply is not valid JSON', function (): void {
    $automation = aiAutomation();
    app()->instance(AiClient::class, new class implements AiClient
    {
        public function messages(array $body, int $timeout): array
        {
            return ['content' => [['type' => 'text', 'text' => 'Leider kein JSON']]];
        }
    });
    config()->set('automations.ai.api_key', 'test-key');
    config()->set('automations.ai.model', 'claude-test');

    $runner = app(WorkflowRunner::class);
    $context = AutomationContext::make(['entry' => ['id' => '42']]);
    $run = $runner->execute(
        $runner->createRun($automation, $context, $automation->nodes->firstWhere('node_key', 't')),
        $context,
    );

    expect($run->status)->toBe('failed')
        ->and($run->nodeRuns()->where('node_key', 'review')->first()->error_message)->toContain('not valid JSON')
        ->and($run->nodeRuns()->where('node_key', 'log')->exists())->toBeFalse();
});
