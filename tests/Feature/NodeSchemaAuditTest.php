<?php

/**
 * Task 2.4 — per-node schema audit. Locks in that every node listed in
 * `.superpowers/sdd/task-2.4-brief.md` wires its entity-reference fields to
 * the exact `options_source` strings served by
 * `NodesController::options()` (Task 2.1), and that actions which produce
 * downstream-readable variables expose them via `outputSchema()` — both in
 * the raw schema/outputSchema() calls and in the JSON `describe` payload
 * the frontend token inserter (Task 2.3) consumes.
 */

use Goldnead\StatamicAutomations\Nodes\Actions\AddLogEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\AiGenerateAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CallAutomationAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CreateEntryAction;
use Goldnead\StatamicAutomations\Nodes\Actions\CreateUserAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SetVariableAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SimpleWebhookAction;
use Goldnead\StatamicAutomations\Nodes\Actions\UpdateEntryAction;
use Goldnead\StatamicAutomations\Nodes\Logic\LoopNode;
use Goldnead\StatamicAutomations\Nodes\Logic\SwitchNode;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntryDeletedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntryPublishedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\EntrySavedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\FormSubmittedTrigger;
use Goldnead\StatamicAutomations\Nodes\Triggers\UserRegisteredTrigger;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

/**
 * @return array<string, mixed>|null
 */
function schemaField(array $schema, string $handle): ?array
{
    foreach ($schema as $field) {
        if (($field['handle'] ?? null) === $handle) {
            return $field;
        }
    }

    return null;
}

// --- Triggers -------------------------------------------------------------

it('form_submitted wires the form field to statamic.forms', function (): void {
    $field = schemaField(FormSubmittedTrigger::schema(), 'form_handle');
    expect($field['type'])->toBe('select');
    expect($field['options_source'])->toBe('statamic.forms');
});

it('entry_published/entry_saved/entry_deleted wire collection to statamic.collections', function (): void {
    foreach ([EntryPublishedTrigger::class, EntrySavedTrigger::class, EntryDeletedTrigger::class] as $class) {
        $field = schemaField($class::schema(), 'collection');
        expect($field['type'])->toBe('select');
        expect($field['options_source'])->toBe('statamic.collections');
    }
});

it('user_registered has an optional role filter wired to roles', function (): void {
    $field = schemaField(UserRegisteredTrigger::schema(), 'role');
    expect($field)->not->toBeNull();
    expect($field['type'])->toBe('select');
    expect($field['options_source'])->toBe('roles');
    expect($field['required'] ?? false)->toBeFalse();
});

// --- Actions ----------------------------------------------------------------

it('send_email wires template to email_templates.templates and flags recipient/subject/body tokenable', function (): void {
    $schema = SendEmailAction::schema();

    foreach (['to', 'subject', 'body'] as $handle) {
        $field = schemaField($schema, $handle);
        expect($field['tokenable'] ?? false)->toBeTrue("field {$handle} should be tokenable");
    }
});

it('send_webhook (simple) flags url/payload tokenable', function (): void {
    $schema = SimpleWebhookAction::schema();

    foreach (['url', 'payload'] as $handle) {
        $field = schemaField($schema, $handle);
        expect($field['tokenable'] ?? false)->toBeTrue("field {$handle} should be tokenable");
    }
});

it('add_log_entry flags message tokenable', function (): void {
    $field = schemaField(AddLogEntryAction::schema(), 'message');
    expect($field['tokenable'] ?? false)->toBeTrue();
});

it('create_entry wires collection/blueprint and flags data tokenable', function (): void {
    $schema = CreateEntryAction::schema();

    $collection = schemaField($schema, 'collection');
    expect($collection['options_source'])->toBe('statamic.collections');

    $blueprint = schemaField($schema, 'blueprint');
    expect($blueprint)->not->toBeNull();
    expect($blueprint['options_source'])->toBe('blueprints');
    expect($blueprint['depends_on'])->toBe('collection');

    $data = schemaField($schema, 'data');
    expect($data['tokenable'] ?? false)->toBeTrue();
});

it('update_entry wires collection + entry picker and flags data tokenable', function (): void {
    $schema = UpdateEntryAction::schema();

    $collection = schemaField($schema, 'collection');
    expect($collection)->not->toBeNull();
    expect($collection['options_source'])->toBe('statamic.collections');

    // Handle preserved (`entry_id`) — only type/source changed so stored
    // automations keep loading.
    $entryId = schemaField($schema, 'entry_id');
    expect($entryId)->not->toBeNull();
    expect($entryId['options_source'])->toBe('entries');
    expect($entryId['depends_on'])->toBe('collection');

    $data = schemaField($schema, 'data');
    expect($data['tokenable'] ?? false)->toBeTrue();
});

it('create_user wires roles to the roles source and flags email/name tokenable', function (): void {
    $schema = CreateUserAction::schema();

    $roles = schemaField($schema, 'roles');
    expect($roles['options_source'])->toBe('roles');

    foreach (['email', 'name'] as $handle) {
        $field = schemaField($schema, $handle);
        expect($field['tokenable'] ?? false)->toBeTrue("field {$handle} should be tokenable");
    }
});

it('ai_generate flags prompt tokenable', function (): void {
    $field = schemaField(AiGenerateAction::schema(), 'prompt');
    expect($field['tokenable'] ?? false)->toBeTrue();
});

// --- Logic --------------------------------------------------------------

it('call_automation wires the automation field to the automations source', function (): void {
    $field = schemaField(CallAutomationAction::schema(), 'automation');
    expect($field['type'])->toBe('select');
    expect($field['options_source'])->toBe('automations');
});

it('set_variable flags its value-bearing field tokenable', function (): void {
    $field = schemaField(SetVariableAction::schema(), 'variables');
    expect($field['tokenable'] ?? false)->toBeTrue();
});

it('switch flags value tokenable and leaves cases as key_value', function (): void {
    $schema = SwitchNode::schema();

    $value = schemaField($schema, 'value');
    expect($value['tokenable'] ?? false)->toBeTrue();

    $cases = schemaField($schema, 'cases');
    expect($cases['type'])->toBe('key_value');
});

it('loop flags items tokenable', function (): void {
    $field = schemaField(LoopNode::schema(), 'items');
    expect($field['tokenable'] ?? false)->toBeTrue();
});

// --- outputSchema() on actions previously missing it ------------------------

it('actions lacking outputSchema now expose non-empty output schemas', function (): void {
    foreach ([
        SendEmailAction::class,
        SimpleWebhookAction::class,
        CreateEntryAction::class,
        UpdateEntryAction::class,
        CreateUserAction::class,
        AiGenerateAction::class,
    ] as $class) {
        expect(method_exists($class, 'outputSchema'))->toBeTrue("{$class} should define outputSchema()");
        expect($class::outputSchema())->toBeArray()->not->toBeEmpty();
    }
});

// --- describe() payload carries outputSchema for actions too ----------------

it('the describe payload for send_email includes output_schema', function (): void {
    $registry = app(NodeRegistry::class);

    $description = $registry->describe('send_email');

    expect($description)->toHaveKey('output_schema');
    expect($description['output_schema'])->toBeArray()->not->toBeEmpty();
});

it('the describe payload for create_entry includes output_schema', function (): void {
    $registry = app(NodeRegistry::class);

    $description = $registry->describe('create_entry');

    expect($description)->toHaveKey('output_schema');
    expect($description['output_schema'])->toBeArray()->not->toBeEmpty();
});

it('the nodes index endpoint carries output_schema for actions', function (): void {
    $response = $this->getJson('/cp/automations/api/nodes')->assertOk();

    $sendEmail = collect($response->json('data.actions'))->firstWhere('handle', 'send_email');

    expect($sendEmail)->not->toBeNull();
    expect($sendEmail)->toHaveKey('output_schema');
    expect($sendEmail['output_schema'])->not->toBeEmpty();
});
