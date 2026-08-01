<?php

/**
 * A test run must never go red because the context is empty.
 *
 * The defect this pins: `leadhub.add_tag` validated its lead reference
 * *before* the test-mode short-circuit. A test run starts from an empty
 * context, `{{ lead.id }}` resolved to nothing, and the node failed with
 * "Both lead reference and tag are required." — on a correctly configured
 * automation. Every action with a required data reference had the same
 * shape, so a whole class of automations could not be tested from the CP.
 *
 * The line drawn (see ActionResult::missingDataReference()):
 *
 *   - static configuration (a tag, a title, a target stage) is validated
 *     BEFORE the test-mode branch — a broken node must still go red;
 *   - data references (`lead_id`, `contact_id`, `opportunity_id` — the
 *     fields a schema declares as `data_reference`) are validated AFTER
 *     it, and only ever fail via `ActionResult::missingDataReference()`.
 *
 * These tests walk every action class in `src/` off the filesystem rather
 * than off the registry, because the LeadHub / Marketing / Webhook Manager
 * actions are only registered when the sibling addon is installed — and it
 * is not, here. A new action added later is therefore covered the moment
 * its file lands.
 */

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Integrations\LeadHub\Actions\AddLeadTagAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Every concrete AutomationAction shipped by the addon.
 *
 * @return array<int, class-string<AutomationAction>>
 */
function allActionClasses(): array
{
    $root = realpath(__DIR__.'/../../src');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    $classes = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'Goldnead\\StatamicAutomations\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->implementsInterface(AutomationAction::class)) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/**
 * A config that fills every field the CP would fill, and deliberately
 * leaves out the data references — exactly the state of a test run whose
 * `{{ lead.id }}` resolved to nothing.
 *
 * `'1'` doubles as a plausible handle and a numeric value, so actions that
 * validate a numeric field (e.g. `leadhub.change_score`) still get past
 * their static checks and actually reach the branch under test.
 *
 * @param  array<int, array<string, mixed>>  $schema
 * @return array<string, mixed>
 */
function configWithoutDataReferences(array $schema): array
{
    $config = [];

    foreach ($schema as $field) {
        $handle = $field['handle'] ?? null;
        $type = $field['type'] ?? 'text';

        if ($handle === null || $type === 'data_reference') {
            continue;
        }

        $config[$handle] = match ($type) {
            'number' => 1,
            'toggle' => false,
            'tags' => ['1'],
            'key_value' => [],
            'select' => isset($field['options'][0])
                ? (is_array($field['options'][0]) ? $field['options'][0]['value'] : $field['options'][0])
                : '1',
            default => '1',
        };
    }

    return $config;
}

/**
 * @return array<int, string> handles of required `data_reference` fields
 */
function requiredDataReferences(array $schema): array
{
    return array_values(array_map(
        fn (array $f) => $f['handle'],
        array_filter(
            $schema,
            fn (array $f) => ($f['type'] ?? null) === 'data_reference' && ($f['required'] ?? false),
        ),
    ));
}

// ---------------------------------------------------------------------------
// The structural property
// ---------------------------------------------------------------------------

it('finds the action classes it claims to audit', function (): void {
    $classes = allActionClasses();

    // Guard against the discovery silently matching nothing and the whole
    // audit below passing vacuously.
    expect(count($classes))->toBeGreaterThanOrEqual(30);
    expect($classes)->toContain(AddLeadTagAction::class);
});

/**
 * The only actions allowed to fail the sweep below, and why.
 *
 * Each of these fails because the dummy config points at a *statically
 * configured* resource that does not exist in the test app — a real
 * configuration error, which a test run is supposed to surface. None of
 * them fails on a data reference.
 *
 * This list is a tripwire, not a suppression: it is asserted exactly, so
 * an action that starts failing (or stops failing) makes the test red and
 * forces a decision. A newly added action that validates a data reference
 * before its test-mode branch lands here and has to be fixed or justified.
 *
 * @return array<string, string>
 */
function expectedTestRunFailures(): array
{
    return [
        'ai_generate' => 'Gated on a Pro licence, which the test app does not have.',
        'call_automation' => 'The dummy target automation handle does not exist.',
        'marketing.send_campaign' => 'The dummy campaign handle does not exist (statamic-marketing is not installed).',
    ];
}

it('no action fails on a missing data reference during a test run', function (): void {
    $failures = [];
    $offenders = [];

    foreach (allActionClasses() as $class) {
        if (! $class::supportsTestMode()) {
            continue;
        }

        $context = AutomationContext::make([], testMode: true);
        $result = app($class)->execute($context, configWithoutDataReferences($class::schema()));

        if (! $result->isFailed()) {
            continue;
        }

        $failures[$class::handle()] = $result->error;

        if (isset($result->output['missing_data_reference'])) {
            $offenders[$class::handle()] = $result->error;
        }
    }

    // The sharp assertion: nothing may fail on an unresolved reference.
    expect($offenders)->toBe([]);

    // The broad one: and nothing else may fail either, beyond the documented
    // static-configuration lookups.
    ksort($failures);
    expect(array_keys($failures))->toBe(array_keys(expectedTestRunFailures()));
});

it('a missing data reference still fails outside a test run', function (): void {
    $checked = 0;

    foreach (allActionClasses() as $class) {
        $references = requiredDataReferences($class::schema());

        if ($references === []) {
            continue;
        }

        $context = AutomationContext::make([], testMode: false);
        $result = app($class)->execute($context, configWithoutDataReferences($class::schema()));

        expect($result->isFailed())->toBeTrue("{$class::handle()} should refuse to run without its data reference");
        expect($result->output['missing_data_reference'] ?? null)
            ->toBeIn($references, "{$class::handle()} should name the reference it is missing");

        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(9);
});

// ---------------------------------------------------------------------------
// The reported case, end to end
// ---------------------------------------------------------------------------

it('leadhub.add_tag previews in a test run when only the lead reference is missing', function (): void {
    $result = app(AddLeadTagAction::class)->execute(
        AutomationContext::make([], testMode: true),
        ['lead_id' => '', 'tag' => 'waitlist'],
    );

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['preview']['tag'])->toBe('waitlist');
});

it('leadhub.add_tag still fails in a test run when the tag is missing', function (): void {
    $result = app(AddLeadTagAction::class)->execute(
        AutomationContext::make(['lead' => ['id' => 'lead-1']], testMode: true),
        ['tag' => ''],
    );

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toBe('A tag is required.');
});

it('names the reference that is missing instead of blaming the tag', function (): void {
    $result = app(AddLeadTagAction::class)->execute(
        AutomationContext::make([], testMode: false),
        ['tag' => 'waitlist'],
    );

    expect($result->isFailed())->toBeTrue();
    expect($result->error)->toContain('Lead');
    expect($result->error)->toContain('{{ lead.id }}');
    // The old message accused the tag, which was set.
    expect($result->error)->not->toContain('tag');
});

it('the persist_leadhub_changes escape hatch still reaches the adapter', function (): void {
    config()->set('automations.test_mode.persist_leadhub_changes', true);

    // With persistence enabled a test run behaves like a live run, so the
    // missing reference is a real failure again.
    $result = app(AddLeadTagAction::class)->execute(
        AutomationContext::make([], testMode: true),
        ['tag' => 'waitlist'],
    );

    expect($result->isFailed())->toBeTrue();
    expect($result->output['missing_data_reference'])->toBe('lead_id');
});

it('missingDataReference carries the handle for the CP to point at', function (): void {
    $result = ActionResult::missingDataReference('lead_id', 'Lead', '{{ lead.id }}');

    expect($result->isFailed())->toBeTrue();
    expect($result->output)->toBe(['missing_data_reference' => 'lead_id']);
});
