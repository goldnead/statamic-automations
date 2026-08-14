<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\BrandContext\Sending\SaidRecently;
use Goldnead\BrandContext\Sending\SenderIdentity;
use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Sending\BrandMailer;
use Goldnead\StatamicAutomations\Sequence\MailSteps;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

/**
 * The domain-neutral mail node: an address, a subject, a body, optionally the
 * rendered HTML of a managed template. It is how a site sends a password
 * reset, a booking confirmation, a receipt, an alert to its own team.
 *
 * **It is not how a site sends marketing mail, and it cannot be made into
 * one.** It asks nobody whether the recipient consented, whether the address
 * is on a suppression list, whether the person opted out, or how much mail
 * they have already had this week — because a password reset must go out
 * regardless of all four. It also carries no unsubscribe link and no sender
 * identification, both of which promotional mail is required to have. That is
 * the correct design for a transactional sender and exactly the wrong one for
 * a newsletter, and the description this node gave of itself until 2.4.0
 * ("transactional *or marketing*") invited the mistake twice: a welcome series
 * on adriangoldner.com and the FamilyStack nurture sequence were both built
 * here, both correct-looking, both sending unchecked.
 *
 * `goldnead/statamic-marketing` contributes `marketing.send_email` for that
 * job. It runs the same send through consent, suppression, opt-out and the
 * frequency cap, in that order, and its mails carry the unsubscribe link and
 * the postal line from the campaign layout. A *transactional* mail to somebody
 * who happens to be a subscriber belongs there too, with its classification
 * set to `transactional` — that exempts it from the cap while keeping the
 * gates that no mail should skip.
 *
 * **The refusal.** Words alone did not hold the last two times, so when the
 * marketing addon is installed this node refuses one specific send: a mail to
 * the very person a marketing run is about (a `marketing.subscribed` /
 * `marketing.unsubscribed` trigger puts them in the context as
 * `subscriber.email` on a named list). That, and only that, is the shape both
 * historical defects had. A mail to anybody else in the same flow — the
 * unsubscribe alert to the team, the "campaign finished" notice to an admin —
 * is untouched, which is why the check compares addresses rather than looking
 * at the trigger. See {@see self::marketingRecipientRefusal()}.
 *
 * **The second way in, which is warned about and not refused.** A run can also
 * reach a subscriber without a marketing trigger: `form_submitted` →
 * `marketing.subscribe` → a mail to the address just subscribed. That is the
 * shipped `marketing_form_to_newsletter` template plus the obvious next node,
 * and it ends in the same place. It is *also* how a site delivers a requested
 * file when it happens to subscribe first and hand over second, and that mail
 * is legitimate. Nothing in the run says which of the two it is, so this shape
 * gets a warning naming `marketing.send_email` and the mail goes out. A
 * refusal here would be a guess, and the thing being guessed at is whether to
 * break somebody's working flow.
 */
class SendEmailAction implements AutomationAction
{
    /**
     * The marketing node this one hands off to. A string rather than an
     * import: the sibling addon is optional and must stay so.
     */
    protected const MARKETING_ACTION = 'Goldnead\\Marketing\\Integrations\\Automations\\Actions\\SendMarketingEmailAction';

    public function __construct(
        protected ?LeadHubAdapter $adapter = null,
        protected ?BrandMailer $mailer = null,
    ) {}

    /**
     * Resolved lazily so `new SendEmailAction()` — tests, ad-hoc use — keeps
     * working without a container argument.
     */
    protected function mailer(): BrandMailer
    {
        return $this->mailer ??= app(BrandMailer::class);
    }

    public static function handle(): string
    {
        return 'send_email';
    }

    public static function label(): string
    {
        return 'Send Email';
    }

    public static function description(): ?string
    {
        return 'Sends one transactional email — a confirmation, a receipt, a password reset, a notice to your own team — with token-resolved fields, as plain text or the rendered HTML of a managed email template (et_templates). '
            .'NOT for marketing mail: this node checks no consent, no suppression list, no opt-out and no frequency cap, and adds neither an unsubscribe link nor the sender details promotional mail has to carry. '
            .'For anything a reader could unsubscribe from, use “Send Marketing Email” (marketing.send_email).';
    }

    public static function group(): string
    {
        return 'Email';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        $schema = [
            [
                'handle' => 'to',
                'label' => 'To',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'Who receives this transactional mail. Addressing the person a marketing run is about '
                    .'(e.g. {{ subscriber.email }} under a Subscriber Confirmed trigger) is refused: that mail is '
                    .'marketing, and it belongs on the “Send Marketing Email” node, which asks for consent, '
                    .'suppression, opt-out and the cap first. A team address in the same flow is fine.',
            ],
            ['handle' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'tokenable' => true],
        ];

        // The template picker only exists when the email-templates addon is
        // installed. Optional coupling: no hard composer dependency, we simply
        // detect the sibling's public facade and hide the field otherwise so
        // the node degrades to a plain-text sender (Freitext-Fallback).
        if (static::emailTemplatesInstalled()) {
            $schema[] = [
                'handle' => 'template',
                'label' => 'Email template',
                'type' => 'select',
                'options' => static::emailTemplateOptions(),
                'options_source' => 'email_templates.templates',
                'required' => false,
                // Declarative UI affordance hint. ConfigPanel keys off this flag
                // to render the "Vorschau" + "Vorlage wählen" buttons (rendered
                // preview + master-detail picker) next to the select. Any future
                // field can opt into the same treatment by setting this.
                'preview' => 'email',
                'help' => 'Optional. Send the rendered HTML of a managed email template (referenced by its slug). Leave empty to send the plain-text body below.',
            ];
        }

        $schema[] = [
            'handle' => 'body',
            'label' => 'Body',
            'type' => 'textarea',
            'required' => true,
            'tokenable' => true,
            'help' => 'Sent as the plain-text body when no template is selected, and used as the fallback if a selected template cannot be resolved.',
        ];
        $schema[] = ['handle' => 'reply_to', 'label' => 'Reply-to', 'type' => 'text', 'required' => false, 'tokenable' => true];
        $schema[] = [
            'handle' => 'from',
            'label' => 'From',
            'type' => 'text',
            'required' => false,
            'tokenable' => true,
            'help' => 'Optional. Ignored for a brand that declares its own settings.mail.from_address — '
                .'the sending address has to match the relay account the brand sends through, and only '
                .'the brand row knows which addresses that account owns.',
        ];
        $schema[] = [
            'handle' => 'dedupe',
            'label' => 'Dedupe key',
            'type' => 'text',
            'required' => false,
            'tokenable' => true,
            'help' => "Optional key to send this email at most once per recipient (e.g. 'welcome'). Prevents duplicate sends if the flow re-fires.",
        ];

        // Opt-in link-click tracking. Only surfaced when the LeadHub addon (with
        // click tracking) is installed. Off by default so tracking is never
        // forced on a send — and only ever applies to HTML (template) sends.
        if (static::leadHubClickTrackingInstalled()) {
            $schema[] = [
                'handle' => 'track_clicks',
                'label' => 'Track link clicks (LeadHub)',
                'type' => 'toggle',
                'required' => false,
                'default' => false,
                'help' => 'Rewrite links in the HTML body to signed LeadHub tracking URLs when the recipient is a known contact. Consent still enforced by LeadHub.',
            ];
        }

        return $schema;
    }

    /**
     * This node sends a mail, so it appears as a row in the mail list.
     *
     * An opt-in rather than something the list infers, because "is this a
     * mail" is a question only the node can answer — a webhook that posts to a
     * mail provider is one, and an action whose group happens to be "Email"
     * might not be. See {@see MailSteps}.
     */
    public static function mailStep(): bool
    {
        return true;
    }

    /**
     * What the mail list shows on this row.
     *
     * The subject, because that is what an editor recognises a mail by, with
     * the template slug as the fallback for a node whose subject comes from
     * the template rather than from the config.
     *
     * @param  array<string, mixed>  $config
     * @return array{label: string, reference: string|null}
     */
    public static function mailSummary(array $config): array
    {
        $subject = $config['subject'] ?? null;
        $template = $config['template'] ?? null;

        return [
            'label' => is_string($subject) && trim($subject) !== '' ? trim($subject) : '',
            'reference' => is_string($template) && $template !== '' ? $template : null,
        ];
    }

    /**
     * Variables this action exposes downstream, e.g. {{ node.sent_to }}.
     * Mirrors the keys returned on the success path of execute().
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'sent_to' => 'string',
            'subject' => 'string',
            'template' => 'string',
            'format' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $to = $config['to'] ?? null;
        $subject = $config['subject'] ?? '(no subject)';
        $body = $config['body'] ?? '';
        $replyTo = $config['reply_to'] ?? null;
        $from = $config['from'] ?? null;
        $templateSlug = $config['template'] ?? null;

        if (empty($to)) {
            return ActionResult::failed('Email "to" is required.');
        }

        // Before anything is rendered, and before the test-mode short-circuit
        // below: pressing Test is how an editor finds out a node is wrong, and
        // "this is marketing mail on the transactional node" is the one wrong
        // thing this node has actually been used for. See the class docblock.
        if (($refusal = $this->marketingRecipientRefusal($context, (string) $to)) !== null) {
            return ActionResult::failed($refusal);
        }

        // When a template is selected AND the email-templates addon is present,
        // resolve the slug to email-ready HTML. A managed entry wins; the inline
        // body is the caller-supplied fallback, so existing inline-body
        // automations keep working and a not-yet-migrated slug still sends the
        // body below.
        $html = null;

        if (! empty($templateSlug) && $this->emailTemplatesAvailable()) {
            $resolved = EmailTemplates::resolve(
                $templateSlug,
                fn () => ['html' => $body, 'subject' => $subject],
            );

            if ($resolved !== null && $resolved->body !== '') {
                $html = $resolved->body;
                $subjectFromTemplate = false;

                if (($subject === '' || $subject === '(no subject)') && ! empty($resolved->subject)) {
                    $subject = $resolved->subject;
                    $subjectFromTemplate = true;
                }

                // The template body (and any template-derived subject) is fetched
                // INSIDE execute() and therefore bypasses NodeExecutor's up-front
                // config resolution. Resolve its {{ tokens }} against the flow
                // context here so personalised templates (e.g. a body containing
                // {{ subscriber.first_name }}) actually render.
                $resolver = app(TokenResolver::class);

                if (is_string($html) && $html !== '') {
                    $html = $resolver->resolveString($html, $context);
                }

                if ($subjectFromTemplate && is_string($subject) && $subject !== '') {
                    $subject = $resolver->resolveString($subject, $context);
                }
            }
        }

        // Final-HTML choke point: rewrite links to LeadHub tracking URLs. Opt-in
        // (per-node track_clicks) and fully guarded — the adapter returns the
        // HTML unchanged when LeadHub is absent or the recipient is unknown. Only
        // HTML (template) sends carry links; plain-text sends are untouched.
        if ($html !== null && ! empty($config['track_clicks'])) {
            $html = $this->leadHub()->rewriteEmailLinks(
                $html,
                is_string($to) ? $to : null,
                $context->get('contact_id') ?? $context->get('contact.id'),
                array_filter([
                    'template' => is_string($templateSlug) ? $templateSlug : null,
                    'automation' => $context->get('automation_id') ?? $context->get('automation.id'),
                ], fn ($v) => $v !== null && $v !== ''),
            );
        }

        $rendered = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'html' => $html,
            'template' => $templateSlug,
            'reply_to' => $replyTo,
            'from' => $from,
        ];

        if ($context->isTestMode() && ! config('automations.test_mode.send_real_emails', false)) {
            return ActionResult::success([
                'preview' => $rendered,
                'note' => 'Test mode — email not sent.',
            ]);
        }

        // Send-once-per-recipient guard. When a `dedupe` key is set, a
        // (dedupe|recipient) fingerprint is cached on first send; a later
        // re-fire of the same flow skips the duplicate instead of re-sending.
        $dedupe = $config['dedupe'] ?? null;
        $dedupeKey = null;

        if (is_string($dedupe) && $dedupe !== '') {
            $dedupeKey = 'automations:send_email:dedupe:'.sha1($dedupe.'|'.$to);

            if (Cache::has($dedupeKey)) {
                return ActionResult::success([
                    'skipped' => true,
                    'reason' => 'dedupe',
                ]);
            }
        }

        try {
            // Sender and transport come from the brand this automation is
            // running for, not from `config('mail.default')`.
            //
            // The node's own `from` used to be the only thing this action set,
            // and the transport was whatever the process had. On a multi-brand
            // host that pairs one brand's address with another brand's relay
            // account: a nurture sequence addressed as `hallo@familystack.de`
            // left through the project that verifies `gldnr.studio`, where the
            // provider refuses the address or substitutes its own. The two
            // halves have to be resolved together, which is what BrandMailer
            // does.
            $sent = $this->mailer()->sendRaw(
                null,
                $html,
                $body,
                function (Message $message, SenderIdentity $identity) use ($to, $subject, $replyTo, $from): void {
                    $message->to($to)->subject($subject);

                    if ($replyTo) {
                        $message->replyTo($replyTo);
                    }

                    // The brand's address is already on the message and wins.
                    // A brand that declares an identity has told the host which
                    // address its relay account owns; a per-node override would
                    // put that guarantee back in the hands of whoever last
                    // edited the flow. Where no brand declares one — every
                    // single-brand install — the node's `from` is still the
                    // only answer there is, and it applies exactly as before.
                    if (! $from) {
                        return;
                    }

                    if ($identity->fromAddress === null) {
                        $message->from($from);

                        return;
                    }

                    // Said out loud, because this is a visible change to
                    // running mail: a flow whose `from` was honoured yesterday
                    // has it dropped the moment the brand row is filled in, and
                    // a change of sender that nobody is told about is one
                    // nobody can trust. Throttled per pair so a sequence
                    // fan-out writes it once, notice rather than warning
                    // because the outcome is correct — only surprising.
                    if (SaidRecently::shouldSay('node-from:'.$from.'>'.$identity->fromAddress)) {
                        Log::notice(sprintf(
                            'The send_email node names the from-address [%s]; the brand sends as [%s] and '
                            .'that wins, because the address has to belong to the relay account the brand '
                            .'sends through. Remove the from on the node, or change the brand.',
                            $from,
                            $identity->fromAddress,
                        ));
                    }
                },
            );

            if (! $sent) {
                // Deliberately before the dedupe stamp below. The cache entry
                // is a record that this recipient has been served, kept for a
                // year; writing it for a mail that never left would suppress
                // the retry that fixing the brand's settings is supposed to
                // enable. The reason is already in the log — BrandMailer wrote
                // it — so this only has to be a failure the run can see.
                return ActionResult::failed(
                    'No usable sender identity for this brand — nothing was sent. See the log.',
                    $rendered,
                );
            }

            if ($dedupeKey !== null) {
                Cache::put($dedupeKey, true, now()->addYear());
            }

            return ActionResult::success([
                'sent_to' => $to,
                'subject' => $subject,
                'template' => $templateSlug,
                'format' => $html !== null ? 'html' : 'text',
            ]);
        } catch (\Throwable $e) {
            return ActionResult::failed($e->getMessage(), $rendered);
        }
    }

    /**
     * The one send this node refuses: marketing mail to the person the run is
     * about. Returns the reason, or null when the send is none of its business.
     *
     * Four conditions, all of them narrow on purpose:
     *
     *  1. **The run is about a marketing subscription.** `subscriber.list` and
     *     `subscriber.email` are what the `marketing.subscribed` and
     *     `.unsubscribed` triggers put in the context, and nothing else does.
     *     A form submission, a webhook, a scheduled sweep never gets here.
     *  2. **This mail goes to that same person.** Compared as addresses, not
     *     inferred from the trigger — because the unsubscribe alert and the
     *     "campaign sent" notice are built on exactly this trigger, address
     *     the team, and are perfectly correct. They must keep working.
     *  3. **`marketing.send_email` exists to hand off to.** Refusing a send
     *     while naming a node the install does not have would leave an editor
     *     with no way forward at all, so without the marketing addon this is
     *     a warning in the log and the mail goes out as before.
     *  4. **The site has not opted out** via
     *     `automations.send_email.refuse_marketing_recipients`. The escape
     *     hatch is site-wide and deliberate rather than a checkbox on the
     *     node: a per-node override is ticked in the same minute the mistake
     *     is made, by the same person, for the same reason. Switching it off
     *     is logged on every send it lets through, or the switch would be the
     *     quietest way back to the original defect.
     *
     * **What it deliberately does not refuse**, and why that is not an
     * oversight: a run that *subscribed* the address itself a node earlier
     * (`form_submitted` → `marketing.subscribe` → here) is only warned about,
     * see {@see self::marketingSubjects()}. Both readings of that flow are
     * real — "welcome them", which is marketing, and "hand them the file they
     * asked for", which is not — and nothing in the context tells them apart.
     * Refusing would break the second one for sites that happen to order the
     * nodes that way round.
     */
    protected function marketingRecipientRefusal(AutomationContext $context, string $to): ?string
    {
        $subject = $this->marketingSubjects($context, $to);

        if ($subject === null) {
            return null;
        }

        [$email, $list, $certain] = $subject;

        $reason = 'The run is about '.$email.'\'s subscription to list ['.$list.'], and this node sends to that '
            .'same person. The transactional sender checks no consent, no suppression list, no opt-out and no '
            .'frequency cap, and adds neither an unsubscribe link nor the sender details promotional mail must '
            .'carry. Move this step onto the "Send Marketing Email" node (marketing.send_email), which asks all '
            .'four first — a genuinely transactional mail to a subscriber belongs there too, with Classification '
            .'set to "transactional". Mail to anybody else in this flow, e.g. a notice to your own team, is '
            .'unaffected.';

        if (! static::marketingSendNodeInstalled()) {
            Log::warning('send_email is sending marketing mail. '.$reason
                .' Install goldnead/statamic-marketing to get that node.');

            return null;
        }

        // The run subscribed this address itself rather than being triggered by
        // the subscription. Said out loud, not refused — see the docblock.
        if (! $certain) {
            Log::warning('send_email may be sending marketing mail. '.$reason);

            return null;
        }

        if (! config('automations.send_email.refuse_marketing_recipients', true)) {
            Log::warning('send_email is sending marketing mail and the refusal is switched off '
                .'(automations.send_email.refuse_marketing_recipients). '.$reason);

            return null;
        }

        return 'This is marketing mail: '.lcfirst($reason);
    }

    /**
     * Is this mail addressed to somebody the run treats as a marketing
     * subscriber? Returns `[email, list, certain]`, or null.
     *
     * Two shapes, and the difference between them is the whole point of the
     * `certain` flag:
     *
     *  - **certain** — a `marketing.subscribed` / `.unsubscribed` trigger put
     *    the person in the context under `subscriber`. The run exists *because
     *    of* their subscription, so a mail to them is about that subscription.
     *    Both historical defects had this shape.
     *  - **not certain** — a node earlier in this run subscribed (or
     *    unsubscribed) the address, and left `list` + `email` in its output.
     *    That is the `marketing_form_to_newsletter` template plus one more
     *    node, which is the obvious next thing to build and the second way to
     *    the same defect. It is also how a lead magnet is delivered by sites
     *    that subscribe first and hand over the file second, and that mail is
     *    legitimate. Warned about, never blocked.
     *
     * @return array{0: string, 1: string, 2: bool}|null
     */
    protected function marketingSubjects(AutomationContext $context, string $to): ?array
    {
        $list = $context->get('subscriber.list');
        $email = $context->get('subscriber.email');

        if (is_string($list) && trim($list) !== '' && is_string($email) && $this->sameAddress($to, $email)) {
            return [$email, trim($list), true];
        }

        $outputs = $context->get('nodes');

        if (! is_array($outputs)) {
            return null;
        }

        foreach ($outputs as $output) {
            if (! is_array($output)) {
                continue;
            }

            // The shape `marketing.subscribe` / `.unsubscribe` return. The
            // uuid (or the test-mode preview flag) is what separates them from
            // any other node that happens to carry an address and a list.
            $isSubscriptionResult = array_key_exists('subscription_uuid', $output)
                || ($output['preview'] ?? false) === true;

            $list = $output['list'] ?? null;
            $email = $output['email'] ?? null;

            if (! $isSubscriptionResult || ! is_string($list) || trim($list) === '' || ! is_string($email)) {
                continue;
            }

            if ($this->sameAddress($to, $email)) {
                return [$email, trim($list), false];
            }
        }

        return null;
    }

    /**
     * Whether the marketing addon's send node is available to hand off to.
     * An overridable seam, like {@see self::emailTemplatesInstalled()}: this
     * repo does not vendor the sibling, so the "addon present" branch is only
     * reachable in tests through a subclass. The sibling's own integration
     * suite is where the class name in {@see self::MARKETING_ACTION} is pinned
     * against the real class — a rename over there would otherwise degrade the
     * refusal to a log line without a single test going red.
     */
    protected static function marketingSendNodeInstalled(): bool
    {
        return class_exists(self::MARKETING_ACTION);
    }

    /**
     * Same mailbox? Case and surrounding whitespace are ignored, a display
     * name is stripped (`Lea <lea@example.test>` is Lea), and a comma-joined
     * recipient list is compared member by member — a display name is not a
     * different recipient, and neither is the second address in a list.
     *
     * What is deliberately NOT normalised: plus-addressing and dots. Two
     * addresses that differ there are two different recipients as far as a
     * refusal is concerned, and guessing otherwise would block sends nobody
     * asked it to block.
     */
    protected function sameAddress(string $a, string $b): bool
    {
        $needle = $this->mailbox($b);

        if ($needle === '') {
            return false;
        }

        foreach (explode(',', $a) as $candidate) {
            if ($this->mailbox($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * The bare address out of `Name <addr>`, `<addr>` or `addr`.
     */
    protected function mailbox(string $address): string
    {
        if (preg_match('/<([^>]*)>/', $address, $matches) === 1) {
            $address = $matches[1];
        }

        return mb_strtolower(trim($address, " \t\n\r\0\x0B\"'"));
    }

    /**
     * Whether the optional email-templates addon is installed. Kept as an
     * overridable seam so both the static schema and instance execution share
     * one detection point (and tests can force the "addon absent" branch).
     */
    protected static function emailTemplatesInstalled(): bool
    {
        return class_exists(EmailTemplates::class);
    }

    /**
     * The single guarded seam to the LeadHub addon. Resolved lazily so
     * `new SendEmailAction()` (tests, ad-hoc) keeps working; the container
     * always has LeadHubAdapter bound.
     */
    protected function leadHub(): LeadHubAdapter
    {
        return $this->adapter ??= app(LeadHubAdapter::class);
    }

    /**
     * Whether LeadHub's click-tracking surface is present. Container-binding
     * based so it lights up exactly when the sibling addon is installed (and so
     * tests can bind a fake under the same key). Overridable seam.
     */
    protected static function leadHubClickTrackingInstalled(): bool
    {
        return app()->bound(LeadHubAdapter::CLICK_LINKER)
            && app()->bound(LeadHubAdapter::RECIPIENT_RESOLVER);
    }

    protected function emailTemplatesAvailable(): bool
    {
        return static::emailTemplatesInstalled();
    }

    /**
     * Slug/title options for the managed `email_templates` collection. Empty
     * (and harmless) when the addon or the collection is not present.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected static function emailTemplateOptions(): array
    {
        if (! static::emailTemplatesInstalled() || ! class_exists(Entry::class)) {
            return [];
        }

        try {
            return collect(Entry::query()->where('collection', 'et_templates')->get())
                ->map(fn ($entry) => [
                    'value' => (string) $entry->slug(),
                    'label' => (string) ($entry->value('title') ?? $entry->slug()),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
