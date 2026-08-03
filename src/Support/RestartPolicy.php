<?php

namespace Goldnead\StatamicAutomations\Support;

/**
 * What happens when somebody enters an automation they have already entered.
 *
 * Until 1.9 the answer was fixed and unwritten: every matching event created
 * another run, so a person who subscribed, unsubscribed and subscribed again
 * got the welcome series twice, in parallel, with both copies still ticking.
 * For a webhook that is right. For a five-mail sequence it is the single most
 * common way to mail somebody twice in one morning.
 *
 * **`always` is still the default and still means exactly what it meant.**
 * That is not a courtesy: every automation in every install is on it today,
 * and a release that changed the answer for automations nobody has looked at
 * would silently stop runs that people are relying on. The three others have
 * to be chosen.
 *
 * The four:
 *
 *  - **always** — enroll again, every time, in parallel with whatever is
 *    already running. Today's behaviour, unchanged.
 *  - **ignore** — if this subject has *ever* been in this automation, do
 *    nothing. The one to pick for a welcome series: there is only one welcome.
 *  - **restart** — cancel whatever pass is still open for this subject and
 *    start a fresh one from the trigger. For a flow whose content is about the
 *    event that just happened, where the newest arrival is the one that
 *    matters and the half-finished older pass is stale.
 *  - **resume** — leave an open pass exactly where it is and add nothing. If
 *    there is no open pass, enroll normally. "Carry on from the last position",
 *    which for a run parked in a delay means the delay keeps running rather
 *    than being restarted.
 *
 * Two of these need to know who the run is about, and that is `subject_key` on
 * the run. A trigger that cannot name a subject (a scheduled sweep, a webhook
 * with no address in it) falls back to `always` and says so in the log —
 * because the alternative, treating every subjectless run as the same subject,
 * would make one nightly sweep block every later one for ever.
 */
enum RestartPolicy: string
{
    case Always = 'always';

    case Ignore = 'ignore';

    case Restart = 'restart';

    case Resume = 'resume';

    /**
     * The reserved key a trigger node carries this under.
     *
     * A node config key rather than a column on `automations`, for the same
     * reason `_on_error` and `_retry_attempts` are: node config is free-form
     * and is already round-tripped by every write path this addon has — CP
     * save, export, import, template install, version revert, flat-file sync.
     * A new column would have to be taught to each of them separately, and the
     * flat-file driver would need a schema change to store it at all.
     */
    public const CONFIG_KEY = '_restart_policy';

    /** The reserved key naming what identifies the subject. See {@see self::CONFIG_KEY}. */
    public const SUBJECT_CONFIG_KEY = '_subject_key';

    /**
     * Context paths tried, in order, when a trigger names no subject key of its
     * own.
     *
     * Ordered from most specific to least: a run seeded by a subscription
     * event carries `subscriber.email`, a CRM event carries `contact.email`,
     * and `email` is the flat fallback a form submission leaves behind. The
     * address is the identity here for the same reason it is in the frequency
     * cap — one person, one inbox, however many records point at it.
     *
     * @var list<string>
     */
    public const DEFAULT_SUBJECT_PATHS = [
        'subscriber.email',
        'contact.email',
        'lead.email',
        'user.email',
        'email',
    ];

    /**
     * Read a stored or configured value.
     *
     * Anything unrecognised — null, an empty string, a value written by a
     * later release, a typo in an imported YAML file — becomes `always`. The
     * conservative direction is the one that changes nothing: a policy nobody
     * could parse must not silently start suppressing enrollments.
     */
    public static function fromValue(?string $value): self
    {
        return $value === null ? self::Always : (self::tryFrom($value) ?? self::Always);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** Does this policy need to know who the run is about? */
    public function needsSubject(): bool
    {
        return $this !== self::Always;
    }

    /**
     * The schema field every trigger node gets, so the policy is set where the
     * enrollment happens rather than in a config file.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function triggerSchema(): array
    {
        return [
            [
                'handle' => self::CONFIG_KEY,
                'label' => 'Re-entry',
                'type' => 'select',
                'options' => [
                    ['value' => self::Always->value, 'label' => 'Enroll again every time'],
                    ['value' => self::Ignore->value, 'label' => 'Ignore — only ever once per contact'],
                    ['value' => self::Restart->value, 'label' => 'Restart from the beginning'],
                    ['value' => self::Resume->value, 'label' => 'Leave the running pass where it is'],
                ],
                'default' => self::Always->value,
                'required' => false,
                'help' => 'What happens when the same contact triggers this automation again. Leave on "Enroll again every time" unless a repeat pass would be wrong — a welcome series usually wants "Ignore".',
            ],
            [
                'handle' => self::SUBJECT_CONFIG_KEY,
                'label' => 'Contact identified by',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Only used by the three re-entry rules above. Leave empty to use the address this run is already about ({{ subscriber.email }}, {{ contact.email }} or {{ email }}).',
            ],
        ];
    }
}
