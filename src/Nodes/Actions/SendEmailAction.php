<?php

namespace Goldnead\StatamicAutomations\Nodes\Actions;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\Mail;

class SendEmailAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'send_email';
    }

    public static function label(): string
    {
        return 'Send Email Notification';
    }

    public static function description(): ?string
    {
        return 'Sends an email with token-resolved fields — plain text, or the rendered HTML of a managed email template.';
    }

    public static function group(): string
    {
        return 'Notifications';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        $schema = [
            ['handle' => 'to', 'label' => 'To', 'type' => 'text', 'required' => true],
            ['handle' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true],
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
            'help' => 'Sent as the plain-text body when no template is selected, and used as the fallback if a selected template cannot be resolved.',
        ];
        $schema[] = ['handle' => 'reply_to', 'label' => 'Reply-to', 'type' => 'text', 'required' => false];
        $schema[] = ['handle' => 'from', 'label' => 'From', 'type' => 'text', 'required' => false];

        return $schema;
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

                if (($subject === '' || $subject === '(no subject)') && ! empty($resolved->subject)) {
                    $subject = $resolved->subject;
                }
            }
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
            return collect(\Statamic\Facades\Entry::query()->where('collection', 'email_templates')->get())
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
