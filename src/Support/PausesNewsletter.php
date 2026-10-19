<?php

namespace Goldnead\StatamicAutomations\Support;

use Goldnead\StatamicAutomations\Automations;
use Goldnead\StatamicAutomations\Models\AutomationNode;

/**
 * "One track at a time": an automation that holds the newsletter back from the
 * people who are inside it.
 *
 * A welcome series or a mini-course is a conversation on a schedule. A
 * broadcast landing in the middle of it is a second voice in the same inbox,
 * and the person on the receiving end reads two mails a day as one sender who
 * has lost the thread. The flag says: while somebody has an open pass through
 * this automation, a broadcast sender (statamic-marketing's campaigns) leaves
 * them out of that send.
 *
 * **Off by default, and off means nothing.** No automation in any install is
 * flagged until somebody switches it on, so a release carrying this changes no
 * send anywhere.
 *
 * This addon only answers the question
 * ({@see Automations::subjectsInPausingRuns()}).
 * What a broadcast does with the answer, and whether a single campaign may
 * override it, is the sender's decision.
 *
 * On the trigger node rather than a column on `automations`, for the reason
 * {@see RestartPolicy::CONFIG_KEY} is one: node config is already round-tripped
 * by every write path (CP save, export, import, template install, version
 * revert, flat-file sync), and a seeder can set it without a schema change.
 */
final class PausesNewsletter
{
    /** The reserved key a trigger node carries this under. */
    public const CONFIG_KEY = '_pauses_newsletter';

    /**
     * Read a stored value. A YAML file may hold `true`, `"true"`, `1` or `"on"`;
     * anything else, including a missing key, is off.
     */
    public static function fromValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Does any node of this graph carry the flag?
     *
     * Not "any trigger node": whether a type counts as a trigger is a question
     * for the node registry, and a trigger whose addon is not installed right
     * now is not in it. The key is reserved and only the trigger form offers
     * it, so its presence is the answer.
     */
    public static function isOn(iterable $nodes): bool
    {
        foreach ($nodes as $node) {
            if (! $node instanceof AutomationNode) {
                continue;
            }

            if (self::fromValue(data_get($node->config ?? [], self::CONFIG_KEY))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The field every trigger offers, appended centrally by the node registry.
     *
     * @return list<array<string, mixed>>
     */
    public static function triggerSchema(): array
    {
        return [
            [
                'handle' => self::CONFIG_KEY,
                'label' => 'Pauses the newsletter',
                'type' => 'toggle',
                'default' => false,
                'required' => false,
                'help' => 'While somebody is inside this automation (waiting, running or queued), the newsletter skips them. They do not get that issue later; the next one reaches them once they are out. Leave it off for anything that is not a sequence the person is meant to follow mail by mail.',
            ],
        ];
    }
}
