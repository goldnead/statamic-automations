<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Concerns\AppliesEnrollmentPolicy;
use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

/**
 * Bridges goldnead/statamic-marketing's domain events into the automation
 * engine: maps each marketing lifecycle event to its trigger handle,
 * normalizes the event via its toPayload() surface, and runs every enabled
 * automation whose trigger node matches.
 *
 * Registered (only when the marketing addon is installed) for the event
 * classes listed in {@see self::EVENT_TRIGGERS}.
 */
class HandleMarketingEvent
{
    use AppliesEnrollmentPolicy;

    /** Marketing event class => automation trigger handle. */
    public const EVENT_TRIGGERS = [
        'Goldnead\\Marketing\\Events\\MarketingSubscribed' => 'marketing.subscribed',
        'Goldnead\\Marketing\\Events\\MarketingUnsubscribed' => 'marketing.unsubscribed',
        'Goldnead\\Marketing\\Events\\CampaignSent' => 'marketing.campaign_sent',
    ];

    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
    ) {}

    public function handle(object $event): void
    {
        $triggerHandle = self::EVENT_TRIGGERS[$event::class] ?? null;

        if ($triggerHandle === null) {
            return;
        }

        $trigger = $this->triggers->instance($triggerHandle);
        if ($trigger === null) {
            return;
        }

        if (! Automation::schemaReady()) {
            return;
        }

        $payload = method_exists($event, 'toPayload') ? $event->toPayload() : (array) $event;

        $automations = Automation::query()
            ->where('enabled', true)
            ->whereHas('nodes', fn ($q) => $q->where('type', $triggerHandle))
            ->with('nodes')
            ->get();

        foreach ($automations as $automation) {
            $triggerNode = $automation->nodes->first(fn ($n) => $n->type === $triggerHandle);
            if ($triggerNode === null) {
                continue;
            }

            if (! $trigger->matches($payload, $triggerNode->config ?? [])) {
                continue;
            }

            $context = $trigger->buildContext($payload, $triggerNode->config ?? []);
            // The trigger node’s re-entry policy. See {@see AppliesEnrollmentPolicy}.
            $run = $this->createEnrolledRun($this->runner, $automation, $triggerNode, $context);

            if ($run === null) {
                continue;
            }

            RunAutomation::dispatch($run->id, $context->all(), false);
        }
    }
}
