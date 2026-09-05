<?php

namespace Goldnead\StatamicAutomations\Sequence;

use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Registries\NodeRegistry;
use Goldnead\StatamicAutomations\Support\DispatchMode;

/**
 * One automation, read as a single sentence.
 *
 * "When `<trigger>`, send `<mail>` to `<recipient>`" — plus the three things
 * somebody deciding whether it works needs beside it: is it on, does it run in
 * the request or in the background, and what did the last few runs do.
 *
 * **This is the rule, not a picture of the automation.** Same split as
 * {@see MailListProjection}: a shape that is not a rule still produces a row,
 * because a reader looking for "which mail goes out when somebody submits the
 * contact form" needs an answer whether or not the flow behind it grew a delay.
 * What the shape decides is whether that row can be edited here
 * ({@see RuleShape}).
 */
class RuleProjection
{
    /** How many recent runs a row carries. Enough to see a pattern, few enough to stay a row. */
    protected const RECENT_RUNS = 5;

    public function __construct(
        protected NodeRegistry $registry,
        protected RuleShape $shape,
        protected MailSteps $mails,
        protected RuleFields $fields,
    ) {}

    /**
     * @return array{
     *     id: int|string,
     *     handle: string,
     *     name: string,
     *     enabled: bool,
     *     trigger: array{handle: string|null, label: string|null},
     *     mail: array{label: string|null, reference: string|null},
     *     recipient: string|null,
     *     template: string|null,
     *     dispatch_mode: string,
     *     editable: bool,
     *     reasons: list<string>,
     *     recent_runs: list<array{id: int|string, status: string, finished_at: string|null}>,
     * }
     */
    public function forAutomation(Automation $automation): array
    {
        $automation->loadMissing(['nodes', 'edges']);

        $shape = $this->shape->evaluate($automation);

        $trigger = $automation->nodes->firstWhere('node_key', $shape['trigger_node_key']);
        $mail = $automation->nodes->firstWhere('node_key', $shape['mail_node_key']);

        $triggerConfig = is_array($trigger?->config) ? $trigger->config : [];
        $mailConfig = is_array($mail?->config) ? $mail->config : [];

        return [
            'id' => $automation->id,
            'handle' => $automation->handle,
            'name' => $automation->name,
            'enabled' => (bool) $automation->enabled,
            'trigger' => [
                'handle' => $trigger?->type,
                'label' => $trigger === null ? null : ($this->registry->describe($trigger->type)['label'] ?? $trigger->type),
            ],
            'mail' => $mail === null
                ? ['label' => null, 'display_label' => null, 'reference' => null]
                : $this->mails->summarise($mail),
            // The recipient is read off the mail node rather than guessed from
            // the trigger: a rule that mails an administrator on every form
            // submission is as common as one that mails the person who
            // submitted, and only the node knows which this is. Through
            // {@see RuleFields}, so the row reads the field the row writes.
            'recipient' => $this->mailField($mail, RuleFields::RECIPIENT, $mailConfig),
            // The template as the node stores it, next to the human `mail.label`
            // above. `mail.reference` is what a reader recognises the mail by
            // and may be a campaign or anything else the node names itself with;
            // this is the value a rule row is allowed to write back.
            'template' => $this->mailField($mail, RuleFields::TEMPLATE, $mailConfig),
            'dispatch_mode' => DispatchMode::fromValue(
                $this->stringOrNull($triggerConfig[DispatchMode::CONFIG_KEY] ?? null)
            )->value,
            'editable' => $shape['editable'],
            'reasons' => $shape['reasons'],
            'recent_runs' => $this->recentRuns($automation),
        ];
    }

    /**
     * @return list<array{id: int|string, status: string, finished_at: string|null}>
     */
    protected function recentRuns(Automation $automation): array
    {
        return AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->orderByDesc('id')
            ->limit(self::RECENT_RUNS)
            ->get()
            ->map(fn (AutomationRun $run) => [
                'id' => $run->id,
                'status' => (string) $run->status,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * One field of the mail node, but only where the node declares it.
     *
     * A key the node has no field for is not a value: it is either left over
     * from an earlier node type or invented by the reader. Either way, showing
     * it would offer an edit that goes nowhere.
     *
     * @param  array<string, mixed>  $config
     */
    protected function mailField(?AutomationNode $mail, string $handle, array $config): ?string
    {
        if ($mail === null || ! $this->fields->declares($mail->type, $handle)) {
            return null;
        }

        return $this->stringOrNull($config[$handle] ?? null);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
