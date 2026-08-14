<?php

namespace Goldnead\StatamicAutomations\Integrations\LeadHub;

use Goldnead\StatamicAutomations\Nodes\Actions\SendEmailAction;
use Illuminate\Support\Facades\Log;

/**
 * A mail an automation sent, written onto the recipient's LeadHub timeline.
 *
 * The contact screen answers "what has this person had from us". Campaigns
 * report themselves there from marketing's own side, and without this the mails
 * an automation sends — often the first ones a person ever receives — were the
 * one part of that answer missing.
 *
 * **What this cannot say.** An automation's mail goes out through the mailer,
 * not through marketing's tracked send path: there is no pixel and no rewritten
 * link, so there is no open and no click to report. One entry, "sent", and the
 * entry says so itself — a timeline that stayed quiet about it would read as
 * "never opened", which is a different and untrue thing.
 *
 * **Nothing here names a class from the sibling addon.** Everything goes through
 * {@see LeadHubAdapter}, which resolves LeadHub out of the container and answers
 * "not installed" without an error. That is what keeps the integration optional
 * — and what lets the tests bind a stand-in instead of vendoring a CRM.
 *
 * **Only for contacts that already exist**, and never fatal: this hangs off the
 * end of a send that has already succeeded. An automation may legitimately mail
 * somebody who is not in the CRM, and a CRM mid-upgrade must not turn a
 * delivered mail into a failed step.
 */
class TimelineRecorder
{
    public const TYPE_SENT = 'automations.mail_sent';

    public function __construct(protected LeadHubAdapter $leadhub) {}

    public function enabled(): bool
    {
        return (bool) config('automations.timeline.enabled', true);
    }

    /**
     * @param  string  $to  The address the mail went to, as the node wrote it —
     *                      possibly `Name <address>`, possibly a list.
     * @param  string  $subject  The subject as the recipient saw it.
     * @param  string|null  $template  The managed template slug, when one was used.
     * @param  string  $runUuid  The automation run, so the entry can be traced back.
     * @param  string  $automation  The automation's name, for whoever reads the row.
     * @param  string  $nodeKey  The step, which together with the run identifies
     *                           this entry.
     */
    public function sent(
        string $to,
        string $subject,
        ?string $template,
        string $runUuid,
        string $automation,
        string $nodeKey,
    ): void {
        // The node's `to` may be `Lea <lea@example.test>` or a comma-separated
        // list — both are valid there and neither is an address the CRM can look
        // up. Without this the entry would simply not appear, silently, for
        // every automation whose recipient is written with a display name.
        $address = $this->mailbox($to);

        if (! $this->enabled() || $address === '') {
            return;
        }

        try {
            // A mail to an address with no contact leaves no trace. Creating a
            // record here would be the automation quietly filing people.
            if ($this->leadhub->findByEmail($address) === null) {
                return;
            }

            $this->leadhub->ingest([
                'email' => $address,
                'type' => self::TYPE_SENT,
                'summary' => (string) __('statamic-automations::timeline.sent', ['subject' => $subject]),
                'source_type' => 'automation_run',
                'source_id' => $runUuid,
                // The run and the step together. A retried job writes the same
                // entry rather than a second one; a sequence that mails the same
                // person three times writes three, because those are three
                // different steps.
                'dedupe_key' => self::TYPE_SENT.':'.$runUuid.':'.$nodeKey,
                'payload' => [
                    'detail' => array_values(array_filter([
                        $subject !== '' ? [
                            'label' => (string) __('statamic-automations::timeline.detail_subject'),
                            'value' => $subject,
                        ] : null,
                        $automation !== '' ? [
                            'label' => (string) __('statamic-automations::timeline.detail_automation'),
                            'value' => $automation,
                        ] : null,
                        $template ? [
                            'label' => (string) __('statamic-automations::timeline.detail_template'),
                            'value' => $template,
                        ] : null,
                        [
                            'label' => (string) __('statamic-automations::timeline.detail_note'),
                            'value' => (string) __('statamic-automations::timeline.no_tracking'),
                        ],
                    ])),
                    'run_uuid' => $runUuid,
                    'node_key' => $nodeKey,
                    'template' => $template,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('statamic-automations: could not write the sent mail to the LeadHub timeline.', [
                'run' => $runUuid,
                'node' => $nodeKey,
                'exception' => $e,
            ]);
        }
    }

    /**
     * The bare address out of whatever the node wrote.
     *
     * A copy of the same three lines in {@see SendEmailAction},
     * deliberately not extracted into a shared helper: the action's version
     * decides whether two addresses are the same person for a refusal, this one
     * decides what to hand a CRM lookup. They agree today and there is no reason
     * they must agree forever, and a shared helper would make the next change to
     * either of them a change to both.
     *
     * The first address only. A step that mails three people writes the entry
     * for the first — the alternative is one CRM lookup per address on a path
     * that hangs off every send, and multi-recipient automation steps are rare.
     */
    protected function mailbox(string $address): string
    {
        $first = explode(',', $address)[0] ?? '';

        if (preg_match('/<([^>]*)>/', $first, $matches) === 1) {
            $first = $matches[1];
        }

        return mb_strtolower(trim($first, " \t\n\r\0\x0B\"'"));
    }
}
