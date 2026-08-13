<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Concerns\AppliesEnrollmentPolicy;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

class HandleEntryPublished
{
    use AppliesEnrollmentPolicy;

    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
        protected AutomationRepository $repository,
    ) {}

    public function handle(object $event): void
    {
        // Statamic fires EntrySaved for both draft saves and publishes.
        // Only fire the entry_published trigger when the saved entry is
        // actually published — matching Webhook Manager's entry.published
        // semantics. Plain draft saves are covered by the generic
        // entry_saved trigger instead.
        if (! $this->entryIsPublished($event)) {
            return;
        }

        $trigger = $this->triggers->instance('entry_published');
        if ($trigger === null) {
            return;
        }

        $automations = $this->repository->enabled()
            ->filter(fn ($automation) => $automation->nodes->contains(fn ($n) => $n->type === 'entry_published'));

        foreach ($automations as $automation) {
            $triggerNode = $automation->nodes->first(fn ($n) => $n->type === 'entry_published');
            if ($triggerNode === null) {
                continue;
            }

            if (! $trigger->matches($event, $triggerNode->config ?? [])) {
                continue;
            }

            $context = $trigger->buildContext($event, $triggerNode->config ?? []);
            // The trigger node’s re-entry policy. See {@see AppliesEnrollmentPolicy}.
            $run = $this->createEnrolledRun($this->runner, $automation, $triggerNode, $context);

            if ($run === null) {
                continue;
            }

            RunAutomation::dispatch($run->id, $context->all(), false);
        }
    }

    protected function entryIsPublished(object $event): bool
    {
        $entry = $event->entry ?? null;

        if (! is_object($entry) || ! method_exists($entry, 'published')) {
            return false;
        }

        try {
            return (bool) $entry->published();
        } catch (\Throwable) {
            return false;
        }
    }
}
