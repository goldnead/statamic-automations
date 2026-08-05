<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Engine\TriggerDispatcher;

/**
 * Whether a trigger starts its run in the queue or inside the request.
 *
 * Until now every run went through the queue, unconditionally
 * ({@see TriggerDispatcher::dispatch()}).
 * For most automations that is right: a five-mail sequence has no business
 * holding up a page load.
 *
 * It is wrong for the case this was built for. A site that sends an immediate
 * mail from its own controller — not `ShouldQueue`, sent inside the request —
 * and then moves that mail into an automation silently turns it into a queue
 * job. The mail no longer goes out while the person is still on the page; it
 * goes out when a worker picks it up. Without this switch, moving such a mail
 * into an automation is not behaviour-neutral, so nobody moves it, and the
 * automation layer stays unused for exactly the mails it would help most.
 *
 * **Sync means a failure in the automation surfaces in the request.** That is
 * the price, and it belongs on the switch rather than in a footnote: whoever
 * turns this on ties their page's response time, and its failure modes, to the
 * automation's runtime.
 *
 * On the trigger node rather than on the automation, for two reasons. An
 * automation may carry several triggers and only one of them is the one that
 * fires inside a request — a nightly sweep on the same automation still
 * belongs in the queue. And it puts the setting where its neighbour already
 * lives: the re-entry policy is read from the same node config two lines
 * earlier ({@see RestartPolicy}).
 */
enum DispatchMode: string
{
    case Async = 'async';

    case Sync = 'sync';

    /**
     * The reserved key a trigger node carries this under.
     *
     * A node config key rather than a column on `automations`, for the same
     * reason {@see RestartPolicy::CONFIG_KEY} is one: node config is free-form
     * and is already round-tripped by every write path this addon has — CP
     * save, export, import, template install, version revert, flat-file sync.
     * A new column would have to be taught to each of them separately.
     */
    public const CONFIG_KEY = '_dispatch_mode';

    /**
     * Read a stored or configured value.
     *
     * Anything unrecognised — null, an empty string, a typo in an imported
     * YAML file, a value written by a later release — becomes `async`. The
     * conservative direction is the one that changes nothing: a value nobody
     * could parse must not start running automations inside web requests.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Async;
    }

    /**
     * The field every trigger offers, appended centrally by the node registry
     * rather than copied into each trigger class.
     *
     * @return list<array<string, mixed>>
     */
    public static function triggerSchema(): array
    {
        return [
            [
                'handle' => self::CONFIG_KEY,
                'label' => 'When this runs',
                'type' => 'select',
                'options' => [
                    ['value' => self::Async->value, 'label' => 'In the background (queued)'],
                    ['value' => self::Sync->value, 'label' => 'Immediately, inside the request'],
                ],
                'default' => self::Async->value,
                'required' => false,
                'help' => 'Background is right for almost everything. Immediate is for a mail that has to be gone before the page finishes loading — and it means a failure in this automation surfaces in the request that triggered it, so the page fails with it.',
            ],
        ];
    }
}
