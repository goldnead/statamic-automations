<?php

namespace Goldnead\StatamicAutomations\Integrations\Map;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\ConditionEvaluator;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Http\Controllers\EmailTemplatePreviewController;
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
use Illuminate\Support\Arr;
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
 * A known person answers every condition: the branch, filter and switch are
 * decided with the engine's own pure parts (`ConditionEvaluator`, the nodes'
 * `evaluate()`), on a context built from her traits, and the way she took is
 * said as a decision line. The trigger match of the event is not read yet.
 *
 * @phpstan-require-extends AutomationMapObjects
 */
trait SimulatesAutomation
{
    /** Steps that change nothing outside the run: left out of the dry run. */
    private const SILENT_STEPS = ['set_variable', 'compose_text', 'add_log_entry'];

    /** Most steps one automation is planned through; a graph larger than that is cut with a line saying so. */
    private const SIM_STEP_LIMIT = 500;

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
        if (! $this->triggerNode($automation)) {
            return [Effect::unknown(__('statamic-automations::automations.sim_no_trigger'))];
        }

        $triggers = $automation->nodes->sortBy('id')->filter(fn (AutomationNode $n) => app(NodeRegistry::class)->kind($n->type) === 'trigger')->values();

        $plan = new class($automation, $person, $this->simSubjects($automation))
        {
            /** @var array<string, true> */
            public array $visited = [];

            /** @var array<string, list<Effect>> what comes after a step, by step: a diamond is built once */
            public array $memo = [];

            public int $effects = 0;

            public ?AutomationContext $context = null;

            public function __construct(
                public readonly Automation $automation,
                public readonly SimPerson $person,
                /** @var array<string, string> */
                public readonly array $subjects,
            ) {}
        };

        // The person's traits as the data a real run would carry: `contact.tags` is `['contact' => ['tags' => …]]`.
        // A test-mode context: nothing it touches may leave the process.
        $plan->context = $person->isKnown() ? AutomationContext::make(Arr::undot($person->traits), true) : null;

        if ($triggers->count() === 1) {
            return $this->simFrom($plan, $triggers[0]->node_key, 'default');
        }

        // More than one trigger: each one begins its own way.
        return [Effect::fork(__('statamic-automations::automations.sim_triggers'), $triggers->map(
            fn (AutomationNode $t) => Effect::alt($this->simName($t), $this->simFrom($plan, $t->node_key, 'default'))
        )->all())];
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

    /**
     * The mail of one `send_email` step as it would read for this person: the template or the step's own text,
     * tokens resolved against sample data with her traits laid over it. Nothing is sent, nothing is stored, no
     * link is rewritten to a tracking address (the registry then makes the HTML safe to look at). The marketing
     * addon's send step is not rendered here: its mail belongs to a campaign.
     *
     * @param  array<string, mixed>  $meta  what the effect said about itself: `node` is the step
     * @return array{subject: string, html: string, from: ?string}|null
     */
    public function mailPreview(string $id, array $meta, SimPerson $person): ?array
    {
        $automation = $this->resolve($id);

        if (! $automation) {
            return null;
        }

        $automation->loadMissing('nodes');
        $node = $automation->nodes->firstWhere('node_key', (string) ($meta['node'] ?? ''));

        if (! $node || $node->type !== SendEmailAction::handle()) {
            return null;
        }

        $mail = app(EmailTemplatePreviewController::class)->mailForConfig(
            is_array($node->config) ? $node->config : [],
            $person->isKnown() ? Arr::undot($person->traits) : [],
        );

        return $mail === null ? null : ['subject' => $mail['subject'], 'html' => $mail['html'], 'from' => $mail['from']];
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

        // A step reached over two ways (both sides of a branch lead to the same mail) is planned once.
        // The planner then holds a graph, not a tree twice the size at every diamond; the planner of
        // the map walks it as a tree and stops at its own budget.
        if (isset($plan->memo[$node->node_key])) {
            return $plan->memo[$node->node_key];
        }

        if (++$plan->effects > self::SIM_STEP_LIMIT) {
            return [Effect::unknown(__('statamic-automations::automations.sim_too_big'))];
        }

        $plan->visited[$node->node_key] = true;

        try {
            return $plan->memo[$node->node_key] = $this->simStep($plan, $node);
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

        // A switched off step does not pass on: the engine ends the run there (NodeExecutor: skipped,
        // WorkflowRunner: stopped), and so does the dry run.
        if ($node->disabled) {
            return [Effect::stop(__('statamic-automations::automations.sim_step_off', ['step' => $name]))];
        }

        if ($type === StopNode::handle()) {
            return [Effect::stop(__('statamic-automations::automations.sim_stop'))];
        }

        if ($type === BranchNode::handle()) {
            $conditions = (array) $node->getConfigValue('conditions', []);
            $words = $this->conditionWords($conditions, (string) $node->getConfigValue('mode', 'all'));

            if ($plan->context !== null) {
                if ($this->dependsOnTheRun($conditions)) {
                    return [Effect::unknown($name, __('statamic-automations::automations.sim_depends_on_run'))];
                }

                $result = BranchNode::evaluate($plan->context, $this->resolvedConfig($node, $plan->context), app(ConditionEvaluator::class));
                $yes = $result->outputHandle === 'true';

                return [Effect::decision(
                    $name,
                    $yes
                        ? __('statamic-automations::automations.sim_took_if', ['condition' => $words])
                        : __('statamic-automations::automations.sim_took_else', ['condition' => $words]),
                    $this->simFrom($plan, $node->node_key, $yes ? 'true' : 'false'),
                )];
            }

            return [Effect::fork($name, [
                Effect::alt(__('statamic-automations::automations.sim_if', ['condition' => $words]), $this->simFrom($plan, $node->node_key, 'true')),
                Effect::alt(__('statamic-automations::automations.sim_else'), $this->simFrom($plan, $node->node_key, 'false')),
            ])];
        }

        if ($type === FilterNode::handle()) {
            $words = $this->conditionWords((array) $node->getConfigValue('conditions', []), (string) $node->getConfigValue('mode', 'all'));

            if ($plan->context !== null) {
                if ($this->dependsOnTheRun((array) $node->getConfigValue('conditions', []))) {
                    return [Effect::unknown($name, __('statamic-automations::automations.sim_depends_on_run'))];
                }

                $passes = ! FilterNode::evaluate($plan->context, $this->resolvedConfig($node, $plan->context), app(ConditionEvaluator::class))->isStopped();

                return $passes
                    ? [Effect::decision($name, __('statamic-automations::automations.sim_took_if', ['condition' => $words]), $next())]
                    : [Effect::decision($name, __('statamic-automations::automations.sim_took_else', ['condition' => $words]), [Effect::stop(__('statamic-automations::automations.sim_filter_stop'))])];
            }

            return [Effect::fork($name, [
                Effect::alt(__('statamic-automations::automations.sim_if', ['condition' => $words]), $next()),
                Effect::alt(__('statamic-automations::automations.sim_else'), [Effect::stop(__('statamic-automations::automations.sim_filter_stop'))]),
            ])];
        }

        if ($type === SwitchNode::handle()) {
            $rows = NodeOutputs::resolve(app(NodeRegistry::class)->outputSpec($type), $node->config ?? []);

            if ($plan->context !== null) {
                if (str_contains((string) $node->getConfigValue('value'), 'nodes.')) {
                    return [Effect::unknown($name, __('statamic-automations::automations.sim_depends_on_run'))];
                }

                $result = (new SwitchNode)->execute($plan->context, $this->resolvedConfig($node, $plan->context));
                $handle = (string) $result->outputHandle;
                $row = collect($rows)->firstWhere('handle', $handle);
                $label = trim((string) ($row['label'] ?? '')) !== '' ? (string) $row['label'] : $handle;

                return [Effect::decision($name, __('statamic-automations::automations.sim_took_case', ['case' => $label]), $this->simFrom($plan, $node->node_key, $handle))];
            }

            $alts = [];

            foreach ($rows as $row) {
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
            $amount = $node->getConfigValue('amount');

            // A wait written as a variable is as long as the variable says: not known here, not "right away".
            if (! is_numeric($amount)) {
                return Effect::chain([
                    new Effect(Effect::WAIT, $name, __('statamic-automations::automations.sim_wait_unknown'), 0, ['duration_known' => false, 'node' => $node->node_key]),
                ], $next());
            }

            $seconds = $this->delaySeconds($node);

            return Effect::chain([new Effect(Effect::WAIT, $this->waitWords($seconds), null, $seconds, ['node' => $node->node_key])], $next());
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
                    // The marketing addon's send step is a campaign mail: an unsubscribed person does not get it.
                    ['node' => $node->node_key, 'template' => $template, 'marketing' => $type === self::MARKETING_SEND],
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
     * The step's config with its tokens resolved against the context, as the engine hands it to the node.
     *
     * @return array<string, mixed>
     */
    private function resolvedConfig(AutomationNode $node, AutomationContext $context): array
    {
        $config = app(TokenResolver::class)->resolve($node->config ?? [], $context);

        return is_array($config) ? $config : [];
    }

    /**
     * Whether a question reads what an earlier step of the run produced (`nodes.x.output`, the engine's own
     * `_` keys). The dry run runs no step, so there is nothing to read: it says it cannot decide.
     *
     * @param  array<int, mixed>  $conditions
     */
    private function dependsOnTheRun(array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $field = trim((string) ($condition['field'] ?? ''));
            $value = is_scalar($condition['value'] ?? null) ? (string) $condition['value'] : '';

            if (str_starts_with($field, 'nodes.') || str_starts_with($field, '_') || str_contains($value, 'nodes.') || str_contains($field, '{{')) {
                return true;
            }
        }

        return false;
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
            $value = array_key_exists('value', $condition) && is_scalar($condition['value']) ? (string) $condition['value'] : '';

            if ($operator === 'includes_tag') {
                $parts[] = __('statamic-automations::automations.sim_cond_tag', ['value' => $value]);

                continue;
            }

            $key = 'statamic-automations::automations.sim_op_'.$operator;
            $word = __($key) !== $key ? __($key) : str_replace('_', ' ', $operator);
            $parts[] = trim($this->fieldWords((string) $condition['field']).' '.$word.($value !== '' ? ' '.$value : ''));
        }

        if ($parts === []) {
            return __('statamic-automations::automations.sim_no_condition');
        }

        $joined = implode($mode === 'any' ? ' '.__('statamic-automations::automations.sim_or').' ' : ' '.__('statamic-automations::automations.sim_and').' ', $parts);

        return Str::limit($joined, 140);
    }

    /** `contact.first_name` as the words the editor sees elsewhere ("Vorname"), any other field spelled out. */
    private function fieldWords(string $field): string
    {
        $field = trim($field);
        $key = 'statamic-automations::automations.map_var_'.Str::afterLast($field, '.');

        return __($key) !== $key ? __($key) : str_replace(['.', '_'], ' ', $field);
    }
}
