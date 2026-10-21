<?php

namespace Goldnead\StatamicAutomations\Integrations\Map;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicAutomations\Integrations\Entitlements\Actions\GrantEntitlementAction;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Nodes\Actions\CallAutomationAction;
use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Goldnead\StatamicAutomations\Nodes\Logic\BranchNode;
use Goldnead\StatamicAutomations\Nodes\Logic\DelayNode;
use Goldnead\StatamicAutomations\Nodes\Logic\FilterNode;
use Goldnead\StatamicAutomations\Nodes\Logic\LoopNode;
use Goldnead\StatamicAutomations\Nodes\Logic\ParallelNode;
use Goldnead\StatamicAutomations\Nodes\Logic\StopNode;
use Goldnead\StatamicAutomations\Nodes\Logic\SwitchNode;
use Goldnead\StatamicAutomations\Nodes\Logic\ThrottleNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitForAutomationNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilNode;
use Goldnead\StatamicAutomations\Nodes\Logic\WaitUntilTimeNode;
use Goldnead\StatamicAutomations\Nodes\Triggers\EventTrigger;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\NodeOutputs;
use Illuminate\Support\Str;

/**
 * What an automation would do in a dry run, read from its graph. Part of
 * {@see AutomationMapObjects}: the map registry asks the provider, so the methods
 * must live on it.
 *
 * Nothing runs. There is no `WorkflowRunner`, no run row, no job and no event:
 * the graph is only read (nodes, edges, the written config) and turned into
 * effects. A step the planner does not understand is an "unknown" effect with
 * the step's own name, never a guess. The automation's own switches stay where
 * they are: a branch asks "which way" and, with nobody known, answers with a fork.
 *
 * @phpstan-require-extends AutomationMapObjects
 */
trait SimulatesAutomation
{
    /** Steps that change nothing outside the run: left out of the dry run. */
    private const SILENT_STEPS = ['set_variable', 'compose_text', 'add_log_entry'];

    /** Where a path can begin: the automation's own trigger. */
    public function paths(string $id): array
    {
        $automation = $this->resolve($id);

        if (! $automation) {
            return [];
        }

        $automation->loadMissing('nodes');

        return $this->triggerNode($automation) ? [new SimEvent(SimEvent::STARTED)] : [];
    }

    /** The dry run shows mails and steps, so it is as guarded as the panel details. */
    public function simulationAuthorized(string $id, $user): bool
    {
        return $this->detailsAuthorized($id, $user);
    }

    /**
     * @return list<Effect>
     */
    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        $automation = $this->resolve($id);

        if (! $automation) {
            return [Effect::unknown(__('statamic-automations::automations.sim_missing'))];
        }

        if (! $automation->enabled) {
            return [Effect::stop(__('statamic-automations::automations.sim_off'))];
        }

        $automation->loadMissing(['nodes', 'edges']);
        $trigger = $this->triggerNode($automation);

        if (! $trigger) {
            return [Effect::unknown(__('statamic-automations::automations.sim_no_trigger'))];
        }

        $plan = new class($automation, $person, $this->simSubjects($automation))
        {
            /** @var array<string, true> */
            public array $visited = [];

            public function __construct(
                public readonly Automation $automation,
                public readonly SimPerson $person,
                /** @var array<string, string> */
                public readonly array $subjects,
            ) {}
        };

        return $this->simFrom($plan, $trigger->node_key, 'default');
    }

    /** The step's own label, else the name of its kind ("Filter"), else its key. */
    private function simName(AutomationNode $node): string
    {
        $label = trim((string) $node->label);

        if ($label !== '') {
            return $label;
        }

        $class = app(NodeRegistry::class)->class($node->type);
        $kind = $class !== null && method_exists($class, 'label') && ! ($class === EventTrigger::class) ? trim((string) $class::label()) : '';

        return $kind !== '' ? $kind : (string) $node->node_key;
    }

    private function triggerNode(Automation $automation): ?AutomationNode
    {
        $registry = app(NodeRegistry::class);

        return $automation->nodes->sortBy('id')->first(fn (AutomationNode $n) => $registry->kind($n->type) === 'trigger');
    }

    /**
     * The subjects of every template the mails of this automation name, in one lookup.
     *
     * @return array<string, string>
     */
    private function simSubjects(Automation $automation): array
    {
        $slugs = [];

        foreach ($automation->nodes as $node) {
            if (in_array($node->type, [SendEmailAction::handle(), self::MARKETING_SEND], true)
                && $this->writtenSubject($node) === null
                && ($slug = $this->writtenTemplate($node)) !== null) {
                $slugs[] = $slug;
            }
        }

        return $slugs === [] ? [] : $this->templateSubjects(array_values(array_unique($slugs)));
    }

    /**
     * The flow behind one output handle of a step.
     *
     * @return list<Effect>
     */
    private function simFrom(object $plan, string $fromKey, string $output): array
    {
        $effects = [];

        foreach ($plan->automation->edges->sortBy('id') as $edge) {
            if ($edge->from_node_key !== $fromKey || $edge->from_output !== $output) {
                continue;
            }

            $node = $plan->automation->nodes->firstWhere('node_key', $edge->to_node_key);

            if ($node) {
                array_push($effects, ...$this->simAt($plan, $node));
            }
        }

        return $effects;
    }

    /**
     * One step and everything after it.
     *
     * @return list<Effect>
     */
    private function simAt(object $plan, AutomationNode $node): array
    {
        if (isset($plan->visited[$node->node_key])) {
            return [Effect::unknown(__('statamic-automations::automations.sim_loop'))];
        }

        $plan->visited[$node->node_key] = true;

        try {
            return $this->simStep($plan, $node);
        } finally {
            unset($plan->visited[$node->node_key]);
        }
    }

    /** @return list<Effect> */
    private function simStep(object $plan, AutomationNode $node): array
    {
        $type = $node->type;
        $next = fn () => $this->simFrom($plan, $node->node_key, 'default');
        $name = $this->simName($node);

        if ($type === StopNode::handle()) {
            return [Effect::stop(__('statamic-automations::automations.sim_stop'))];
        }

        if ($type === BranchNode::handle()) {
            $words = $this->conditionWords((array) $node->getConfigValue('conditions', []), (string) $node->getConfigValue('mode', 'all'));

            return [Effect::fork($name, [
                Effect::alt(__('statamic-automations::automations.sim_if', ['condition' => $words]), $this->simFrom($plan, $node->node_key, 'true')),
                Effect::alt(__('statamic-automations::automations.sim_else'), $this->simFrom($plan, $node->node_key, 'false')),
            ])];
        }

        if ($type === FilterNode::handle()) {
            $words = $this->conditionWords((array) $node->getConfigValue('conditions', []), (string) $node->getConfigValue('mode', 'all'));

            return [Effect::fork($name, [
                Effect::alt(__('statamic-automations::automations.sim_if', ['condition' => $words]), $next()),
                Effect::alt(__('statamic-automations::automations.sim_else'), [Effect::stop(__('statamic-automations::automations.sim_filter_stop'))]),
            ])];
        }

        if ($type === SwitchNode::handle()) {
            $alts = [];

            foreach (NodeOutputs::resolve(app(NodeRegistry::class)->outputSpec($type), $node->config ?? []) as $row) {
                $label = trim((string) ($row['label'] ?? '')) !== '' ? (string) $row['label'] : (string) $row['handle'];
                $alts[] = Effect::alt(__('statamic-automations::automations.sim_case', ['case' => $label]), $this->simFrom($plan, $node->node_key, (string) $row['handle']));
            }

            return [Effect::fork($name, $alts)];
        }

        if ($type === ParallelNode::handle()) {
            $all = [];

            foreach (NodeOutputs::resolve(app(NodeRegistry::class)->outputSpec($type), $node->config ?? []) as $row) {
                array_push($all, ...$this->simFrom($plan, $node->node_key, (string) $row['handle']));
            }

            return $all;
        }

        if ($type === LoopNode::handle()) {
            // The body runs once for each item, and how many items there are is not known here.
            return [Effect::unknown($name, __('statamic-automations::automations.sim_loop_body'))->withChildren($this->simFrom($plan, $node->node_key, LoopNode::OUTPUT_DONE))];
        }

        if ($type === DelayNode::handle()) {
            $seconds = $this->delaySeconds($node);

            return Effect::chain([Effect::wait($this->waitWords($seconds), $seconds)], $next());
        }

        if (in_array($type, [WaitUntilNode::handle(), WaitUntilTimeNode::handle(), ThrottleNode::handle()], true)) {
            return Effect::chain([
                new Effect(Effect::WAIT, $name, __('statamic-automations::automations.sim_wait_unknown'), 0, ['duration_known' => false]),
            ], $next());
        }

        if ($type === WaitForAutomationNode::handle()) {
            $awaited = $this->writtenAutomation($node);

            return Effect::chain([
                new Effect(Effect::WAIT, $name, $awaited !== null ? __('statamic-automations::automations.sim_waits_for', ['automation' => $awaited]) : null, 0, ['duration_known' => false]),
            ], $next());
        }

        if (in_array($type, [SendEmailAction::handle(), self::MARKETING_SEND], true)) {
            $template = $this->writtenTemplate($node);

            return Effect::chain([
                Effect::mail(
                    $this->mailTitle($node, $plan->subjects),
                    $template !== null ? __('statamic-automations::automations.sim_template', ['template' => $template]) : null,
                    0,
                    ['node' => $node->node_key, 'template' => $template],
                ),
            ], $next());
        }

        if ($type === CallAutomationAction::handle()) {
            $called = $this->writtenAutomation($node);
            $target = $called !== null ? $this->resolve($called) : null;
            $effects = [];

            if ($target) {
                $effects[] = Effect::emit(new SimEvent(SimEvent::ENTERED, target: ['type' => self::TYPE, 'id' => $this->idOf($target)]));
            } else {
                $effects[] = Effect::unknown($name, __('statamic-automations::automations.sim_call_missing', ['automation' => (string) $called]));
            }

            return Effect::chain($effects, $next());
        }

        if ($type === GrantEntitlementAction::handle()) {
            $slug = $this->productSlugOf($node);

            return Effect::chain([
                Effect::access($slug !== null ? __('statamic-automations::automations.sim_grants', ['slug' => $slug]) : $name, null, ['slug' => $slug]),
                Effect::emit(new SimEvent(SimEvent::GRANTED, via: ['grants'], data: ['slug' => $slug])),
            ], $next());
        }

        if (in_array($type, self::SILENT_STEPS, true)) {
            return $next();
        }

        // Any other step does something this planner does not model. It says so.
        return Effect::chain([Effect::unknown($name, __('statamic-automations::automations.sim_step_unknown', ['type' => $type]))], $next());
    }

    /**
     * The condition list in words, e.g. "lead.status = Qualified". Long lists are cut.
     *
     * @param  array<int, mixed>  $conditions
     */
    private function conditionWords(array $conditions, string $mode): string
    {
        $parts = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! isset($condition['field'])) {
                continue;
            }

            $operator = (string) ($condition['operator'] ?? 'equals');
            $key = 'statamic-automations::automations.sim_op_'.$operator;
            $word = __($key) !== $key ? __($key) : str_replace('_', ' ', $operator);
            $value = array_key_exists('value', $condition) && is_scalar($condition['value']) ? ' '.$condition['value'] : '';
            $parts[] = trim((string) $condition['field']).' '.$word.$value;
        }

        if ($parts === []) {
            return __('statamic-automations::automations.sim_no_condition');
        }

        $joined = implode($mode === 'any' ? ' '.__('statamic-automations::automations.sim_or').' ' : ' '.__('statamic-automations::automations.sim_and').' ', $parts);

        return Str::limit($joined, 140);
    }
}
