<?php

namespace Goldnead\StatamicAutomations\Concerns;

use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

/**
 * The body every sister-addon listener shares: take a trigger handle and a
 * domain event, find the enabled automations that start on that handle, ask the
 * trigger whether each one wants this event, and dispatch a run.
 *
 * It lives in a trait rather than a base class because the listeners differ only
 * in their event maps, and the maps are the part worth reading. Before this
 * existed there was one copy of the loop; a second addon family would have made
 * it two, and the second copy is where the drift starts.
 */
trait RunsAutomationsForEvent
{
    use AppliesEnrollmentPolicy;

    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
    ) {}

    /**
     * The trigger handle for an event class, or null when this listener does
     * not know it.
     *
     * A loop rather than an array lookup, and that is not style. The map keys
     * are class-strings of packages that may or may not be installed, so static
     * analysis narrows the array to whichever of them happen to be loadable in
     * the run at hand and then decides the lookup can never hit. The comparison
     * says the same thing in a form that survives the class existing or not.
     *
     * @param  array<string, string>  ...$maps  event class-string => trigger handle
     */
    protected function handleForEvent(object $event, array ...$maps): ?string
    {
        $class = $event::class;

        foreach ($maps as $map) {
            foreach ($map as $eventClass => $handle) {
                if ($eventClass === $class) {
                    return $handle;
                }
            }
        }

        return null;
    }

    /**
     * Start every automation that begins on `$handle` and accepts this event.
     */
    protected function runFor(string $handle, object $event): void
    {
        $trigger = $this->triggers->instance($handle);

        if ($trigger === null || ! Automation::schemaReady()) {
            return;
        }

        $automations = Automation::query()
            ->where('enabled', true)
            ->whereHas('nodes', fn ($q) => $q->where('type', $handle))
            ->with('nodes')
            ->get();

        foreach ($automations as $automation) {
            $node = $automation->nodes->first(fn ($n) => $n->type === $handle);

            if ($node === null || ! $trigger->matches($event, $node->config ?? [])) {
                continue;
            }

            $context = $trigger->buildContext($event, $node->config ?? []);
            $run = $this->createEnrolledRun($this->runner, $automation, $node, $context);

            if ($run === null) {
                continue;
            }

            RunAutomation::dispatch($run->id, $context->all(), false);
        }
    }
}
