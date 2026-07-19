<?php

/**
 * Public extensibility API (Phase 0 substrate): the register* surface, the
 * OptionSourceRegistry, the AutomationLogicNode contract and defensive
 * describe(). A third party registers a custom Action, Logic node and Option
 * source; each shows up in the node-library payload and runs in the engine.
 */

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Engine\NodeExecutor;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode as AutomationNodeModel;
use Goldnead\StatamicAutomations\Registries\OptionSourceRegistry;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->actingAsSuperUser();
});

it('resolves a registered option source through the registry', function (): void {
    Automations::registerOptionSource('acme.things', fn (Request $r) => [
        ['value' => 'a', 'label' => 'Alpha'],
        ['value' => 'b', 'label' => 'Beta'],
    ]);

    $registry = app(OptionSourceRegistry::class);
    expect($registry->has('acme.things'))->toBeTrue();

    $resolved = $registry->resolve('acme.things', Request::create('/'));
    expect($resolved)->toBe([
        ['value' => 'a', 'label' => 'Alpha'],
        ['value' => 'b', 'label' => 'Beta'],
    ]);
});

it('normalises loose option-source output and serves it via the endpoint', function (): void {
    // Assoc map + plain scalars are coerced to the canonical {value,label} shape.
    Automations::registerOptionSource('acme.colors', fn (Request $r) => ['red' => 'Red', 'green' => 'Green']);

    $data = $this->getJson('/cp/automations/api/options/acme.colors')->assertOk()->json('data');

    expect($data)->toBe([
        ['value' => 'red', 'label' => 'Red'],
        ['value' => 'green', 'label' => 'Green'],
    ]);
});

it('returns an empty list for an unknown option source, never a fatal', function (): void {
    $this->getJson('/cp/automations/api/options/does.not.exist')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('still resolves the built-in statamic option sources through the registry', function (): void {
    \Statamic\Facades\Collection::make('blog')->title('Blog')->save();

    foreach (['collections', 'statamic.collections'] as $source) {
        $data = $this->getJson("/cp/automations/api/options/{$source}")->assertOk()->json('data');
        expect(collect($data)->firstWhere('value', 'blog'))->toBe(['value' => 'blog', 'label' => 'Blog']);
    }
});

it('registers a custom action via the handle-less overload and shows it in the library', function (): void {
    Automations::registerAction(AcmePingAction::class);

    $actions = $this->getJson('/cp/automations/api/nodes')->assertOk()->json('data.actions');
    $entry = collect($actions)->firstWhere('handle', 'acme.ping');

    expect($entry)->not->toBeNull();
    expect($entry['label'])->toBe('Acme Ping');
    expect($entry['group'])->toBe('Acme');
    expect($entry['output_schema'])->toHaveKey('pong');
});

it('runs a registered custom action through the engine NodeExecutor', function (): void {
    Automations::registerAction(AcmePingAction::class);

    $node = new AutomationNodeModel(['node_key' => 'n', 'type' => 'acme.ping', 'config' => []]);
    $result = app(NodeExecutor::class)->execute($node, AutomationContext::make());

    expect($result->isSuccess())->toBeTrue();
    expect($result->output['pong'])->toBeTrue();
});

it('registers a custom logic node and runs it through the engine', function (): void {
    Automations::registerLogicNode(AcmeGateNode::class);

    $data = $this->getJson('/cp/automations/api/nodes')->assertOk()->json('data.logic');
    expect(collect($data)->firstWhere('handle', 'acme.gate'))->not->toBeNull();

    $node = new AutomationNodeModel(['node_key' => 'g', 'type' => 'acme.gate', 'config' => ['open' => false]]);
    $result = app(NodeExecutor::class)->execute($node, AutomationContext::make());
    expect($result->isStopped())->toBeTrue();

    $node2 = new AutomationNodeModel(['node_key' => 'g', 'type' => 'acme.gate', 'config' => ['open' => true]]);
    $result2 = app(NodeExecutor::class)->execute($node2, AutomationContext::make());
    expect($result2->isSuccess())->toBeTrue();
});

it('describe() fails loudly on a malformed registration', function (): void {
    expect(fn () => Automations::describe('Acme\\Nope\\DoesNotExist', 'action'))
        ->toThrow(InvalidArgumentException::class);

    // A class that does not implement the action contract cannot be an action.
    expect(fn () => Automations::registerAction(AcmeGateNode::class))
        ->toThrow(InvalidArgumentException::class);
});

it('confirms the built-in logic nodes satisfy the AutomationLogicNode contract', function (): void {
    foreach ([
        \Goldnead\StatamicAutomations\Nodes\Logic\FilterNode::class,
        \Goldnead\StatamicAutomations\Nodes\Logic\BranchNode::class,
        \Goldnead\StatamicAutomations\Nodes\Logic\StopNode::class,
        \Goldnead\StatamicAutomations\Nodes\Logic\SwitchNode::class,
        \Goldnead\StatamicAutomations\Nodes\Logic\LoopNode::class,
        \Goldnead\StatamicAutomations\Nodes\Logic\ParallelNode::class,
        \Goldnead\StatamicAutomations\Nodes\Logic\DelayNode::class,
        \Goldnead\StatamicAutomations\Nodes\Logic\ThrottleNode::class,
        \Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilNode::class,
    ] as $class) {
        expect(is_subclass_of($class, AutomationLogicNode::class))->toBeTrue("{$class} implements AutomationLogicNode");
    }
});

/**
 * A third-party action.
 */
class AcmePingAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'acme.ping';
    }

    public static function label(): string
    {
        return 'Acme Ping';
    }

    public static function description(): ?string
    {
        return 'Pings Acme.';
    }

    public static function group(): string
    {
        return 'Acme';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [];
    }

    public static function outputSchema(): array
    {
        return ['pong' => 'boolean'];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return ActionResult::success(['pong' => true]);
    }
}

/**
 * A third-party logic node — only implements the AutomationLogicNode contract.
 */
class AcmeGateNode implements AutomationLogicNode
{
    public static function handle(): string
    {
        return 'acme.gate';
    }

    public static function label(): string
    {
        return 'Acme Gate';
    }

    public static function description(): ?string
    {
        return 'Stops the flow unless "open" is true.';
    }

    public static function group(): string
    {
        return 'Acme';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            ['handle' => 'open', 'label' => 'Open', 'type' => 'toggle', 'default' => false],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        return ! empty($config['open'])
            ? ActionResult::success(['open' => true])
            : ActionResult::stopped('Gate closed.');
    }
}
