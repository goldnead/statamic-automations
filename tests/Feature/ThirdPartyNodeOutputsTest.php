<?php

/**
 * The promise, end to end: a node type this package has never heard of
 * declares three outputs, and all three are real everywhere it matters.
 *
 * Before 1.7.0 they were real in exactly one place — the class itself.
 * `NodeRegistry::describe()` did not expose outputs, so the canvas gave the
 * node a single `default` handle whatever it declared; the user could
 * therefore only ever wire one of the three, and `FlowValidator` could not
 * hold the graph to the other two because it had no way to ask. The runner
 * was the one layer that always worked, because it routes on the handle the
 * node returns and never on the node's type.
 *
 * `acme.review` below is a deliberately plain third-party node: it declares
 * `outputSpec()` and nothing else this package knows about. Its canvas half
 * — three rendered handles from the same declaration — is
 * `tests/js/node-card-outputs.test.js`.
 */

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationEdge;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Goldnead\StatamicAutomations\Support\DeclaresOutputs;
use Goldnead\StatamicAutomations\Support\NodeOutputs;

beforeEach(function (): void {
    $this->actingAsSuperUser();
    Automations::registerLogicNode(AcmeReviewNode::class);
});

/** A review automation wired out of all three of the node's outputs. */
function reviewAutomation(string $verdict = 'approved'): Automation
{
    $automation = Automation::create(['name' => 'Review', 'handle' => 'review', 'enabled' => true]);

    AutomationNode::create(['automation_id' => $automation->id, 'node_key' => 't', 'type' => 'manual']);
    AutomationNode::create([
        'automation_id' => $automation->id, 'node_key' => 'rev', 'type' => 'acme.review',
        'config' => ['verdict' => $verdict],
    ]);

    foreach (['approved', 'rejected', 'escalated'] as $output) {
        AutomationNode::create([
            'automation_id' => $automation->id, 'node_key' => "log_{$output}", 'type' => 'add_log_entry',
            'config' => ['message' => "took {$output}"],
        ]);
        AutomationEdge::create([
            'automation_id' => $automation->id,
            'from_node_key' => 'rev', 'from_output' => $output, 'to_node_key' => "log_{$output}",
        ]);
    }

    AutomationEdge::create([
        'automation_id' => $automation->id, 'from_node_key' => 't', 'from_output' => 'default', 'to_node_key' => 'rev',
    ]);

    return $automation->fresh(['nodes', 'edges']);
}

it('ships all three declared handles to the canvas in the node library', function (): void {
    $entry = collect($this->getJson('/cp/automations/api/nodes')->assertOk()->json('data.logic'))
        ->firstWhere('handle', 'acme.review');

    expect($entry)->not->toBeNull();
    expect($entry['outputs']['version'])->toBe(NodeOutputs::VERSION);
    expect(NodeOutputs::handles($entry['outputs']))->toBe(['approved', 'rejected', 'escalated']);

    // And the same three off the registry directly, which is what the
    // validator asks.
    expect(app(NodeRegistry::class)->outputsFor('acme.review'))->toBe(['approved', 'rejected', 'escalated']);
});

it('accepts an automation wired out of all three, and names a fourth handle it never declared', function (): void {
    $automation = reviewAutomation();

    expect(app(FlowValidator::class)->validate($automation))->toBe([]);

    AutomationEdge::create([
        'automation_id' => $automation->id,
        'from_node_key' => 'rev', 'from_output' => 'invented', 'to_node_key' => 'log_approved',
    ]);

    $issues = app(FlowValidator::class)->validate($automation->fresh(['nodes', 'edges']));

    expect($issues)->toHaveCount(1);
    expect($issues[0]['code'])->toBe('edge_unknown_output');
    expect($issues[0]['node_key'])->toBe('rev');
    expect($issues[0]['message'])->toContain('invented');
    expect($issues[0]['message'])->toContain('approved, rejected, escalated');
});

it('routes the run down whichever of the three the node returns', function (): void {
    foreach (['approved', 'rejected', 'escalated'] as $verdict) {
        $automation = reviewAutomation($verdict);
        $context = AutomationContext::make([]);
        $runner = app(WorkflowRunner::class);

        $run = $runner->execute($runner->createRun($automation, $context), $context);

        expect($run->status)->toBe(AutomationRun::STATUS_SUCCESS);
        expect($run->nodeRuns()->pluck('node_key')->all())->toBe(['t', 'rev', "log_{$verdict}"]);

        $automation->edges()->delete();
        $automation->nodes()->delete();
        $automation->delete();
    }
});

it('lets a third-party node with a plain outputs() method declare handles too', function (): void {
    // No spec, just the `outputs()` a node author would reach for first. The
    // registry serialises it for the canvas and reads it for the validator.
    Automations::registerLogicNode(AcmeTriageNode::class);

    $registry = app(NodeRegistry::class);

    expect($registry->outputsFor('acme.triage'))->toBe(['urgent', 'normal']);
    expect(NodeOutputs::handles($registry->outputSpec('acme.triage')))->toBe(['urgent', 'normal']);
});

it('gives a .branch type that declares nothing the true/false the validator holds it to', function (): void {
    Automations::registerLogicNode(AcmeSuffixBranchNode::class);

    $registry = app(NodeRegistry::class);

    // The 1.5.5 rule, moved off the canvas and onto the server: the suffix is
    // what a type that declares nothing gets, not a cap on what it may declare.
    expect($registry->outputsFor('acme.branch'))->toBe(['true', 'false']);
    expect(app(NodeRegistry::class)->outputsFor('acme.review'))->toBe(['approved', 'rejected', 'escalated']);
});

/**
 * A third-party logic node with three outputs of its own choosing.
 */
class AcmeReviewNode implements AutomationLogicNode
{
    use DeclaresOutputs;

    public static function outputSpec(): array
    {
        return NodeOutputs::fixed([
            ['handle' => 'approved', 'label' => 'Approved'],
            ['handle' => 'rejected', 'label' => 'Rejected'],
            ['handle' => 'escalated', 'label' => 'Escalated'],
        ], primary: 'approved');
    }

    public static function handle(): string
    {
        return 'acme.review';
    }

    public static function label(): string
    {
        return 'Acme Review';
    }

    public static function description(): ?string
    {
        return 'Routes a review to one of three outcomes.';
    }

    public static function group(): string
    {
        return 'Acme';
    }

    public static function schema(): array
    {
        return [['handle' => 'verdict', 'label' => 'Verdict', 'type' => 'text']];
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return ActionResult::success(['verdict' => $config['verdict']], (string) $config['verdict']);
    }
}

/**
 * A third-party node that declares its outputs the plain way.
 */
class AcmeTriageNode implements AutomationLogicNode
{
    public static function outputs(array $config = []): array
    {
        return ['urgent', 'normal'];
    }

    public static function handle(): string
    {
        return 'acme.triage';
    }

    public static function label(): string
    {
        return 'Acme Triage';
    }

    public static function description(): ?string
    {
        return null;
    }

    public static function group(): string
    {
        return 'Acme';
    }

    public static function schema(): array
    {
        return [];
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return ActionResult::success([], 'normal');
    }
}

/**
 * A third-party node named for the branch convention and declaring nothing.
 */
class AcmeSuffixBranchNode implements AutomationLogicNode
{
    public static function handle(): string
    {
        return 'acme.branch';
    }

    public static function label(): string
    {
        return 'Acme Branch';
    }

    public static function description(): ?string
    {
        return null;
    }

    public static function group(): string
    {
        return 'Acme';
    }

    public static function schema(): array
    {
        return [];
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return ActionResult::branch(true);
    }
}
