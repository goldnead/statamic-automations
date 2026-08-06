<?php

namespace Goldnead\StatamicAutomations;

use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Contracts\AutomationLogicNode;
use Goldnead\StatamicAutomations\Contracts\AutomationNode;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;
use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;
use Goldnead\StatamicAutomations\Nodes\Triggers\EventTrigger;
use Goldnead\StatamicAutomations\Registries\ActionRegistry;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Registries\OptionSourceRegistry;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

/**
 * Public entry point for the Automations addon.
 *
 * Accessible via the Automations facade. Lets developers register their
 * own triggers, actions, logic nodes, option sources and event triggers
 * from any service provider — the same public surface the addon's own
 * built-ins are registered through.
 */
class Automations
{
    /**
     * Built-in node handles, populated via {@see registerBuiltIn()} during boot.
     *
     * @var array<string,bool>
     */
    protected array $builtInHandles = [];

    /**
     * Registered event-trigger definitions, keyed by handle.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $eventTriggers = [];

    public function __construct(
        protected TriggerRegistry $triggers,
        protected ActionRegistry $actions,
        protected NodeRegistry $nodes,
        protected OptionSourceRegistry $optionSources,
    ) {}

    /**
     * Mark a node handle as built-in.
     */
    public function registerBuiltIn(string $handle): self
    {
        $this->builtInHandles[$handle] = true;

        return $this;
    }

    public function isBuiltIn(string $handle): bool
    {
        return isset($this->builtInHandles[$handle]);
    }

    /**
     * Register a trigger.
     */
    public function trigger(string $handle, string $class): self
    {
        $this->triggers->register($handle, $class);
        $this->nodes->register($handle, $class, 'trigger');

        return $this;
    }

    /**
     * Register an action.
     */
    public function action(string $handle, string $class): self
    {
        $this->actions->register($handle, $class);
        $this->nodes->register($handle, $class, 'action');

        return $this;
    }

    /**
     * Register a custom logic node.
     *
     * Use this for filter / branch / stop / delay style nodes.
     */
    public function node(string $handle, string $class): self
    {
        $this->nodes->register($handle, $class, 'logic');

        return $this;
    }

    /**
     * Register an action node through the public API.
     *
     * Handle-less form: `registerAction(MyAction::class)` derives the handle
     * from `MyAction::handle()` (Statamic-idiomatic). The two-arg form
     * `registerAction('handle', MyAction::class)` stays supported.
     *
     * A malformed registration throws (see {@see describe()}) rather than
     * silently no-op'ing.
     */
    public function registerAction(string $handleOrClass, ?string $class = null): self
    {
        [$handle, $class] = $this->normalizeRegistration($handleOrClass, $class, 'action');

        return $this->action($handle, $class);
    }

    /**
     * Register a trigger node through the public API. Handle-less overload
     * supported, same as {@see registerAction()}.
     */
    public function registerTrigger(string $handleOrClass, ?string $class = null): self
    {
        [$handle, $class] = $this->normalizeRegistration($handleOrClass, $class, 'trigger');

        return $this->trigger($handle, $class);
    }

    /**
     * Register a logic node through the public API. Handle-less overload
     * supported, same as {@see registerAction()}.
     */
    public function registerLogicNode(string $handleOrClass, ?string $class = null): self
    {
        [$handle, $class] = $this->normalizeRegistration($handleOrClass, $class, 'logic');

        return $this->node($handle, $class);
    }

    /**
     * Register a dynamic option-source resolver, keyed by source handle.
     *
     * The resolver is `fn (\Illuminate\Http\Request $request): array` (or an
     * invokable class-string) returning `[['value' => ..., 'label' => ...], ...]`.
     * A node schema field can then reference it via `options_source: '<handle>'`
     * and its <select> is populated by the CP config form — full parity with
     * the built-in Statamic sources.
     */
    public function registerOptionSource(string $handle, callable|string $resolver): self
    {
        $this->optionSources->register($handle, $resolver);

        return $this;
    }

    /**
     * Turn an arbitrary application event into an automation trigger.
     *
     * One generic listener is subscribed per registered event and funnels the
     * event into the existing TriggerDispatcher — the dispatch pipeline is not
     * duplicated. The trigger appears in the CP trigger list automatically.
     *
     * @param  class-string  $eventClass
     * @param  array{
     *     handle: string,
     *     label?: string,
     *     group?: string,
     *     description?: string|null,
     *     schema?: array<int, array<string, mixed>>,
     *     output_schema?: array<string, mixed>,
     *     payload?: string|callable|class-string,
     *     matches?: callable|class-string,
     * }  $definition
     */
    public function registerEventTrigger(string $eventClass, array $definition): self
    {
        $definition = $this->normalizeEventTriggerDefinition($eventClass, $definition);
        $handle = $definition['handle'];

        $instance = new EventTrigger($definition);

        // Configured instance so the dispatcher's matches()/buildContext()
        // read this definition; NodeRegistry meta so the library shows the
        // per-handle label/schema/output_schema.
        $this->triggers->registerInstance($handle, $instance);
        $this->nodes->register($handle, EventTrigger::class, 'trigger', [
            'label' => $definition['label'],
            'description' => $definition['description'] ?? null,
            'group' => $definition['group'] ?? 'Events',
            'schema' => $definition['schema'] ?? [],
            'supports_test_mode' => true,
            'output_schema' => $definition['output_schema'] ?? [],
        ]);

        $this->eventTriggers[$handle] = $definition;

        // One generic listener per registered event → the existing dispatcher.
        if (class_exists($eventClass)) {
            Event::listen($eventClass, function ($event) use ($handle) {
                app(TriggerDispatcher::class)->dispatch($handle, $event);
            });
        }

        return $this;
    }

    /**
     * Register every event trigger declared in `config('automations.event_triggers')`.
     * Called at boot; closures aren't config-serialisable, so the config path
     * supports the declarative payload/matcher forms (dot-path / class-string).
     */
    public function bootEventTriggersFromConfig(): self
    {
        $configured = config('automations.event_triggers', []);

        if (! is_array($configured)) {
            return $this;
        }

        foreach ($configured as $eventClass => $definition) {
            if (! is_string($eventClass) || ! is_array($definition)) {
                continue;
            }

            $this->registerEventTrigger($eventClass, $definition);
        }

        return $this;
    }

    /**
     * Registered event-trigger definitions, keyed by handle.
     *
     * @return array<string, array<string, mixed>>
     */
    public function eventTriggers(): array
    {
        return $this->eventTriggers;
    }

    public function optionSources(): OptionSourceRegistry
    {
        return $this->optionSources;
    }

    /**
     * Validate a node class and return a schema-rich description of it, or
     * throw {@see InvalidArgumentException} if the registration is malformed.
     *
     * This is the defensive gate the register*() methods run so a bad
     * registration fails loudly at boot rather than producing a broken,
     * silently-missing node in the CP.
     *
     * @param  'trigger'|'action'|'logic'|null  $expectedKind
     * @return array{handle: string, kind: string, class: class-string}
     */
    public function describe(string $class, ?string $expectedKind = null): array
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("Automation node class [{$class}] does not exist.");
        }

        if (! is_subclass_of($class, AutomationNode::class)) {
            throw new InvalidArgumentException(
                "[{$class}] must implement ".AutomationNode::class.'.'
            );
        }

        $handle = $class::handle();

        if (! is_string($handle) || $handle === '') {
            throw new InvalidArgumentException("[{$class}]::handle() must return a non-empty string.");
        }

        $kind = $expectedKind ?? $this->inferKind($class);

        $this->assertImplementsKind($class, $kind);

        return ['handle' => $handle, 'kind' => $kind, 'class' => $class];
    }

    /**
     * Register an automation template in the CP template catalog. Same array
     * shape as the built-ins: handle, name, description, requires[], nodes[],
     * edges[]. Templates are not license-gated — they only materialize nodes
     * the user could also build by hand.
     *
     * @param  array<string, mixed>  $template
     */
    public function template(array $template): self
    {
        app(TemplateRegistry::class)->register($template);

        return $this;
    }

    public function triggers(): TriggerRegistry
    {
        return $this->triggers;
    }

    public function actions(): ActionRegistry
    {
        return $this->actions;
    }

    public function nodes(): NodeRegistry
    {
        return $this->nodes;
    }

    /**
     * Resolve the (handle, class) pair for a register*() call, supporting the
     * handle-less overload, and validate it defensively.
     *
     * @param  'trigger'|'action'|'logic'  $kind
     * @return array{0: string, 1: class-string}
     */
    protected function normalizeRegistration(string $handleOrClass, ?string $class, string $kind): array
    {
        if ($class === null) {
            $class = $handleOrClass;
            $description = $this->describe($class, $kind);

            return [$description['handle'], $class];
        }

        // Explicit-handle form: still validate the class/kind contract.
        $this->describe($class, $kind);

        return [$handleOrClass, $class];
    }

    /**
     * @param  class-string  $class
     * @return 'trigger'|'action'|'logic'
     */
    protected function inferKind(string $class): string
    {
        if (is_subclass_of($class, AutomationTrigger::class)) {
            return 'trigger';
        }

        if (is_subclass_of($class, AutomationAction::class)) {
            return 'action';
        }

        return 'logic';
    }

    /**
     * @param  class-string  $class
     */
    protected function assertImplementsKind(string $class, string $kind): void
    {
        $contract = match ($kind) {
            'trigger' => AutomationTrigger::class,
            'action' => AutomationAction::class,
            default => null, // logic — validated below
        };

        if ($contract !== null && ! is_subclass_of($class, $contract)) {
            throw new InvalidArgumentException("[{$class}] must implement {$contract} to register as a {$kind}.");
        }

        // A logic node must be runnable: either the formal contract, or an
        // executable method the NodeExecutor understands (execute / evaluate).
        // This keeps back-compat with built-ins registered as logic that carry
        // an AutomationAction execute() (e.g. set_variable, call_automation).
        if ($kind === 'logic'
            && ! is_subclass_of($class, AutomationLogicNode::class)
            && ! method_exists($class, 'execute')
            && ! method_exists($class, 'evaluate')) {
            throw new InvalidArgumentException(
                "[{$class}] must implement ".AutomationLogicNode::class
                .' or expose an execute()/evaluate() method to register as a logic node.'
            );
        }
    }

    /**
     * Validate and normalise an event-trigger definition.
     *
     * @param  class-string  $eventClass
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    protected function normalizeEventTriggerDefinition(string $eventClass, array $definition): array
    {
        $handle = $definition['handle'] ?? null;

        if (! is_string($handle) || $handle === '') {
            throw new InvalidArgumentException(
                "Event trigger for [{$eventClass}] requires a non-empty 'handle'."
            );
        }

        $definition['handle'] = $handle;
        $definition['label'] = $definition['label'] ?? ucwords(str_replace(['_', '.', '-'], ' ', $handle));
        $definition['group'] = $definition['group'] ?? 'Events';
        $definition['event'] = $eventClass;

        return $definition;
    }
}
