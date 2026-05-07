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
        return 'Sends a plain-text email with token-resolved fields.';
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
        return [
            ['handle' => 'to', 'label' => 'To', 'type' => 'text', 'required' => true],
            ['handle' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true],
            ['handle' => 'body', 'label' => 'Body', 'type' => 'textarea', 'required' => true],
            ['handle' => 'reply_to', 'label' => 'Reply-to', 'type' => 'text', 'required' => false],
            ['handle' => 'from', 'label' => 'From', 'type' => 'text', 'required' => false],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $to = $config['to'] ?? null;
        $subject = $config['subject'] ?? '(no subject)';
        $body = $config['body'] ?? '';
        $replyTo = $config['reply_to'] ?? null;
        $from = $config['from'] ?? null;

        if (empty($to)) {
            return ActionResult::failed('Email "to" is required.');
        }

        $rendered = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
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
            Mail::raw($body, function ($message) use ($to, $subject, $replyTo, $from) {
                $message->to($to)->subject($subject);

                if ($replyTo) {
                    $message->replyTo($replyTo);
                }

                if ($from) {
                    $message->from($from);
                }
            });

            return ActionResult::success([
                'sent_to' => $to,
                'subject' => $subject,
            ]);
        } catch (\Throwable $e) {
            return ActionResult::failed($e->getMessage(), $rendered);
        }
    }
}
