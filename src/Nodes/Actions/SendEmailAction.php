<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Integrations\LeadHub\LeadHubAdapter;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendEmailAction implements AutomationAction
{
    public function __construct(protected ?LeadHubAdapter $adapter = null)
    {
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
        $schema[] = ['handle' => 'from', 'label' => 'From', 'type' => 'text', 'required' => false, 'tokenable' => true];
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
            $resolved = \Goldnead\EmailTemplates\Facades\EmailTemplates::resolve(
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
            $build = function ($message) use ($to, $subject, $replyTo, $from) {
                $message->to($to)->subject($subject);

                if ($replyTo) {
                    $message->replyTo($replyTo);
                }

                if ($from) {
                    $message->from($from);
                }
            };

            if ($html !== null) {
                Mail::html($html, $build);
            } else {
                Mail::raw($body, $build);
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
        return class_exists(\Goldnead\EmailTemplates\Facades\EmailTemplates::class);
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
        if (! static::emailTemplatesInstalled() || ! class_exists(\Statamic\Facades\Entry::class)) {
            return [];
        }

        try {
            return collect(\Statamic\Facades\Entry::query()->where('collection', 'et_templates')->get())
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
