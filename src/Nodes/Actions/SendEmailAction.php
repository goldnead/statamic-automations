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

class SendEmailAction implements AutomationAction
{
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
        return 'Sends a transactional or marketing email with token-resolved fields — plain text, or the rendered HTML of a managed email template (et_templates).';
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
            ['handle' => 'to', 'label' => 'To', 'type' => 'text', 'required' => true, 'tokenable' => true],
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
