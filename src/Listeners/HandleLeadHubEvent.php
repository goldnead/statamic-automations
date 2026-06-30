<?php

namespace Goldnead\StatamicAutomations\Listeners;

use Goldnead\StatamicAutomations\Engine\WorkflowRunner;
use Goldnead\StatamicAutomations\Jobs\RunAutomation;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Registries\TriggerRegistry;

/**
 * Bridges LeadHub's domain events into the automation engine: maps each
 * LeadHub lifecycle event to its trigger handle, normalizes the event into a
 * `lead` context, and runs every enabled automation whose trigger node matches.
 *
 * Registered (only when LeadHub is installed) for the LeadHub event classes
 * listed in {@see self::EVENT_TRIGGERS}.
 */
class HandleLeadHubEvent
{
    /** LeadHub event class => automation trigger handle. */
    public const EVENT_TRIGGERS = [
        'Goldnead\\Leadhub\\Events\\LeadHubContactCreated' => 'leadhub.lead_created',
        'Goldnead\\Leadhub\\Events\\LeadHubStatusChanged' => 'leadhub.lead_status_changed',
        'Goldnead\\Leadhub\\Events\\LeadHubTagAdded' => 'leadhub.lead_tag_added',
        'Goldnead\\Leadhub\\Events\\LeadHubNoteAdded' => 'leadhub.lead_note_added',
        'Goldnead\\Leadhub\\Events\\LeadHubFollowupDue' => 'leadhub.lead_follow_up_due',
    ];

    public function __construct(
        protected TriggerRegistry $triggers,
        protected WorkflowRunner $runner,
    ) {
    }

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

        $payload = $this->normalize($event);

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
            $run = $this->runner->createRun($automation, $context, $triggerNode);

            RunAutomation::dispatch($run->id, $context->all(), false);
        }
    }

    /**
     * Normalize a LeadHub event into a plain array the triggers understand:
     * `lead` plus any event-specific extras carried in metadata.
     *
     * @return array<string, mixed>
     */
    protected function normalize(object $event): array
    {
        $contact = $event->contact ?? null;
        $metadata = is_array($event->metadata ?? null) ? $event->metadata : [];

        $lead = $this->normalizeLead($contact);

        return array_merge([
            'lead' => $lead,
            // Common extras used by the status / tag / follow-up triggers.
            'previous_status' => $metadata['from'] ?? null,
            'new_status' => $metadata['to'] ?? null,
            'tag' => $metadata['tag_name'] ?? null,
        ], $metadata);
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeLead(mixed $contact): array
    {
        if ($contact === null) {
            return [];
        }

        $get = fn (string $key) => $contact->{$key} ?? null;

        $tags = [];
        try {
            $relation = $contact->tags ?? null;
            if ($relation !== null && method_exists($relation, 'pluck')) {
                $tags = $relation->pluck('name')->all();
            }
        } catch (\Throwable) {
            $tags = [];
        }

        return array_filter([
            'id' => $get('id'),
            'uuid' => $get('uuid'),
            'email' => $get('email'),
            'full_name' => method_exists($contact, 'displayName') ? $contact->displayName() : $get('full_name'),
            'first_name' => $get('first_name'),
            'last_name' => $get('last_name'),
            'company' => $get('company'),
            'phone' => $get('phone'),
            'status' => $get('status'),
            'source' => $get('source'),
            'tags' => $tags,
        ], fn ($v) => $v !== null);
    }
}
