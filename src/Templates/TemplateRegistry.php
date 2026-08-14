<?php

namespace Goldnead\StatamicAutomations\Templates;

/**
 * Returns the catalog of built-in automation templates.
 *
 * Templates are stored as plain PHP arrays inside this package and
 * are *copied* into user-owned automations on install (the install
 * controller materializes nodes and edges into the database).
 */
class TemplateRegistry
{
    /**
     * Templates registered by other packages (e.g. goldnead/statamic-marketing)
     * or the host app, keyed by handle. The registry is bound as a singleton
     * so boot-time registrations survive until the CP requests the catalog.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $custom = [];

    /**
     * Register an additional template. Same array shape as the built-ins:
     * handle, name, description, requires[], nodes[], edges[]. A handle that
     * collides with a built-in replaces it.
     *
     * @param  array<string, mixed>  $template
     */
    public function register(array $template): self
    {
        if (empty($template['handle']) || empty($template['nodes'])) {
            throw new \InvalidArgumentException('Automation templates require at least a handle and nodes.');
        }

        $this->custom[$template['handle']] = $template;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $builtIn = array_values(array_filter(
            $this->builtIn(),
            fn (array $template) => ! isset($this->custom[$template['handle']]),
        ));

        return array_merge($builtIn, array_values($this->custom));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function builtIn(): array
    {
        return [
            $this->newLeadNotification(),
            $this->formSubmissionToWebhook(),
            $this->qualifiedLeadToCrm(),
            $this->workshopInquiryFlow(),
            $this->leadMagnetDelivery(),
            $this->followUpReminder(),
            $this->entryPublishedNotification(),
            $this->webhookFailureAlert(),
            $this->scheduledDigest(),
            $this->inboundWebhookToEntry(),
            $this->aiTriageInquiry(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $handle): ?array
    {
        foreach ($this->all() as $template) {
            if ($template['handle'] === $handle) {
                return $template;
            }
        }

        return null;
    }

    protected function newLeadNotification(): array
    {
        return [
            'handle' => 'new_lead_notification',
            'name' => 'New Lead Notification',
            'description' => 'Email the admin whenever a new LeadHub lead is created.',
            'requires' => ['leadhub'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'leadhub.lead_created', 'position_x' => 0, 'position_y' => 0],
                ['node_key' => 'email', 'type' => 'send_email', 'position_x' => 250, 'position_y' => 0, 'config' => [
                    'to' => 'admin@example.com',
                    'subject' => 'New lead: {{ lead.email }}',
                    'body' => "A new lead just signed up:\n\nName: {{ lead.full_name }}\nEmail: {{ lead.email }}\nSource: {{ lead.source }}",
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'email'],
            ],
        ];
    }

    protected function formSubmissionToWebhook(): array
    {
        return [
            'handle' => 'form_submission_to_webhook',
            'name' => 'Form Submission to Webhook',
            'description' => 'Forward form submissions to an external webhook.',
            'requires' => [],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'form_submitted', 'position_x' => 0, 'position_y' => 0, 'config' => ['form_handle' => null]],
                ['node_key' => 'webhook', 'type' => 'send_webhook', 'position_x' => 250, 'position_y' => 0, 'config' => [
                    'url' => 'https://example.com/incoming',
                    'method' => 'POST',
                    'payload' => '{"form": "{{ form.handle }}", "email": "{{ form.email }}"}',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'webhook'],
            ],
        ];
    }

    protected function qualifiedLeadToCrm(): array
    {
        return [
            'handle' => 'qualified_lead_to_crm',
            'name' => 'Qualified Lead to CRM',
            'description' => 'Push leads to a CRM destination as soon as they reach status "Qualified".',
            'requires' => ['leadhub'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'leadhub.lead_status_changed', 'position_x' => 0, 'position_y' => 0, 'config' => ['new_status' => 'Qualified']],
                ['node_key' => 'webhook', 'type' => 'send_webhook', 'position_x' => 250, 'position_y' => -80, 'config' => [
                    'url' => 'https://example.com/crm',
                    'payload' => '{"email": "{{ lead.email }}", "name": "{{ lead.full_name }}"}',
                ]],
                ['node_key' => 'note', 'type' => 'leadhub.add_note', 'position_x' => 250, 'position_y' => 80, 'config' => [
                    'lead_id' => '{{ lead.id }}',
                    'body' => 'Sent to CRM by Statamic Automations.',
                ]],
                ['node_key' => 'follow_up', 'type' => 'leadhub.create_follow_up', 'position_x' => 500, 'position_y' => 0, 'config' => [
                    'lead_id' => '{{ lead.id }}',
                    'due_in_days' => 3,
                    'note' => 'Check in after CRM hand-off.',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'webhook'],
                ['from_node_key' => 'webhook', 'to_node_key' => 'note'],
                ['from_node_key' => 'note', 'to_node_key' => 'follow_up'],
            ],
        ];
    }

    protected function workshopInquiryFlow(): array
    {
        return [
            'handle' => 'workshop_inquiry_flow',
            'name' => 'Workshop Inquiry Flow',
            'description' => 'Capture workshop inquiries from a form and create a tagged LeadHub lead with follow-up.',
            'requires' => ['leadhub'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'form_submitted', 'position_x' => 0, 'position_y' => 0, 'config' => ['form_handle' => 'workshop_request']],
                ['node_key' => 'lead', 'type' => 'leadhub.create_or_update_lead', 'position_x' => 280, 'position_y' => 0, 'config' => [
                    'email' => '{{ form.email }}',
                    'first_name' => '{{ form.first_name }}',
                    'last_name' => '{{ form.last_name }}',
                    'source' => 'workshop-form',
                ]],
                ['node_key' => 'tag', 'type' => 'leadhub.add_tag', 'position_x' => 560, 'position_y' => 0, 'config' => [
                    'lead_id' => '{{ lead.id }}',
                    'tag' => 'Workshop',
                ]],
                ['node_key' => 'email', 'type' => 'send_email', 'position_x' => 840, 'position_y' => -80, 'config' => [
                    'to' => 'admin@example.com',
                    'subject' => 'Workshop inquiry from {{ form.email }}',
                    'body' => 'New workshop inquiry. Message:\n\n{{ form.message }}',
                ]],
                ['node_key' => 'follow_up', 'type' => 'leadhub.create_follow_up', 'position_x' => 840, 'position_y' => 80, 'config' => [
                    'lead_id' => '{{ lead.id }}',
                    'due_in_days' => 2,
                    'note' => 'Reply to workshop inquiry.',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'lead'],
                ['from_node_key' => 'lead', 'to_node_key' => 'tag'],
                ['from_node_key' => 'tag', 'to_node_key' => 'email'],
                ['from_node_key' => 'email', 'to_node_key' => 'follow_up'],
            ],
        ];
    }

    protected function leadMagnetDelivery(): array
    {
        return [
            'handle' => 'lead_magnet_delivery',
            'name' => 'Lead Magnet Delivery',
            // The one built-in that mails a member of the public rather than
            // the site's own team, and it stays on the transactional node
            // because the mail *is* the answer to the request that created it:
            // one file, asked for by name, seconds earlier. Nothing here
            // subscribes anybody. A second mail — a nudge, a follow-up, an
            // offer — is marketing and belongs on `marketing.send_email` after
            // a subscription, which is what the description says out loud so
            // that the next step somebody adds is the right one.
            'description' => 'Capture an email through a form, deliver the requested file by email, and create a tagged LeadHub lead. '
                .'The delivery mail is transactional: it answers the request and nothing more. It does not subscribe anyone, and any '
                .'follow-up mail needs a subscription and the "Send Marketing Email" node.',
            'requires' => ['leadhub'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'form_submitted', 'position_x' => 0, 'position_y' => 0, 'config' => [
                    'form_handle' => 'lead_magnet',
                ]],
                ['node_key' => 'filter', 'type' => 'filter', 'position_x' => 260, 'position_y' => 0, 'config' => [
                    'mode' => 'all',
                    'conditions' => [
                        ['field' => 'form.email', 'operator' => 'is_not_empty'],
                    ],
                ]],
                ['node_key' => 'deliver', 'type' => 'send_email', 'position_x' => 520, 'position_y' => -80, 'config' => [
                    'to' => '{{ form.email }}',
                    'subject' => 'Your free guide is here',
                    'body' => "Hi {{ form.first_name }},\n\nThanks for signing up. You can download your guide here:\n\nhttps://example.com/lead-magnet.pdf\n\n— The team",
                ]],
                ['node_key' => 'lead', 'type' => 'leadhub.create_or_update_lead', 'position_x' => 520, 'position_y' => 80, 'config' => [
                    'email' => '{{ form.email }}',
                    'first_name' => '{{ form.first_name }}',
                    'source' => 'lead-magnet',
                ]],
                ['node_key' => 'tag', 'type' => 'leadhub.add_tag', 'position_x' => 800, 'position_y' => 80, 'config' => [
                    'lead_id' => '{{ lead.id }}',
                    'tag' => 'Lead Magnet',
                ]],
                ['node_key' => 'log', 'type' => 'add_log_entry', 'position_x' => 1060, 'position_y' => 0, 'config' => [
                    'level' => 'info',
                    'message' => 'Lead magnet delivered to {{ form.email }}',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'filter'],
                ['from_node_key' => 'filter', 'to_node_key' => 'deliver'],
                ['from_node_key' => 'deliver', 'to_node_key' => 'lead'],
                ['from_node_key' => 'lead', 'to_node_key' => 'tag'],
                ['from_node_key' => 'tag', 'to_node_key' => 'log'],
            ],
        ];
    }

    protected function followUpReminder(): array
    {
        return [
            'handle' => 'follow_up_reminder',
            'name' => 'Follow-up Reminder',
            'description' => 'Email yourself a reminder whenever a LeadHub follow-up becomes due.',
            'requires' => ['leadhub'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'leadhub.lead_follow_up_due', 'position_x' => 0, 'position_y' => 0, 'config' => [
                    'window' => 'due_today',
                ]],
                ['node_key' => 'email', 'type' => 'send_email', 'position_x' => 280, 'position_y' => 0, 'config' => [
                    'to' => 'admin@example.com',
                    'subject' => 'Follow-up due: {{ lead.full_name }}',
                    'body' => "A follow-up is due today.\n\nLead: {{ lead.full_name }} ({{ lead.email }})\nNote: {{ follow_up.note }}\nDue at: {{ follow_up.due_at }}",
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'email'],
            ],
        ];
    }

    protected function entryPublishedNotification(): array
    {
        return [
            'handle' => 'entry_published_notification',
            'name' => 'Entry Published Notification',
            'description' => 'Send a webhook whenever an entry in a particular collection is published.',
            'requires' => [],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'entry_published', 'position_x' => 0, 'position_y' => 0, 'config' => ['collection' => 'articles']],
                ['node_key' => 'webhook', 'type' => 'send_webhook', 'position_x' => 280, 'position_y' => 0, 'config' => [
                    'url' => 'https://hooks.slack.com/services/REPLACE/ME',
                    'payload' => '{"text": "New article published: {{ entry.title }} ({{ entry.url }})"}',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'webhook'],
            ],
        ];
    }

    protected function webhookFailureAlert(): array
    {
        return [
            'handle' => 'webhook_failure_alert',
            'name' => 'Webhook Failure Alert',
            'description' => 'Email the admin when an outbound webhook keeps failing.',
            'requires' => ['webhook_manager'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'webhook_manager.outbound_failed', 'position_x' => 0, 'position_y' => 0, 'config' => ['min_attempts' => 3]],
                ['node_key' => 'email', 'type' => 'send_email', 'position_x' => 280, 'position_y' => 0, 'config' => [
                    'to' => 'admin@example.com',
                    'subject' => 'Webhook destination failing',
                    'body' => 'Destination {{ webhook.destination }} has failed {{ webhook.attempts }} times.',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'email'],
            ],
        ];
    }

    protected function scheduledDigest(): array
    {
        return [
            'handle' => 'scheduled_digest',
            'name' => 'Scheduled Daily Digest',
            'description' => 'Run every morning and email a digest. Uses the Scheduled trigger.',
            'requires' => [],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'scheduled', 'position_x' => 0, 'position_y' => 0, 'config' => [
                    'frequency' => 'daily',
                    'time' => '08:00',
                ]],
                ['node_key' => 'email', 'type' => 'send_email', 'position_x' => 280, 'position_y' => 0, 'config' => [
                    'to' => 'admin@example.com',
                    'subject' => 'Your daily digest — {{ scheduled.frequency }}',
                    'body' => "Good morning!\n\nThis is your scheduled digest.",
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'email'],
            ],
        ];
    }

    protected function inboundWebhookToEntry(): array
    {
        return [
            'handle' => 'inbound_webhook_to_entry',
            'name' => 'Inbound Webhook → Entry',
            'description' => 'Receive a webhook (via Webhook Manager), de-duplicate it, and create an entry.',
            'requires' => ['webhook_manager'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'webhook_received', 'position_x' => 0, 'position_y' => 0, 'config' => [
                    'endpoint' => 'orders',
                ]],
                ['node_key' => 'dedupe', 'type' => 'throttle', 'position_x' => 260, 'position_y' => 0, 'config' => [
                    'key' => '{{ webhook.payload.id }}',
                    'window_minutes' => 60,
                ]],
                ['node_key' => 'entry', 'type' => 'create_entry', 'position_x' => 520, 'position_y' => 0, 'config' => [
                    'collection' => 'orders',
                    'data' => ['title' => 'Order {{ webhook.payload.id }}'],
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'dedupe'],
                ['from_node_key' => 'dedupe', 'to_node_key' => 'entry'],
            ],
        ];
    }

    protected function aiTriageInquiry(): array
    {
        return [
            'handle' => 'ai_triage_inquiry',
            'name' => 'AI Triage of Inquiries',
            'description' => 'Summarise an inbound form inquiry with AI and email the summary to the team.',
            'requires' => [],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'form_submitted', 'position_x' => 0, 'position_y' => 0, 'config' => [
                    'form_handle' => 'contact',
                ]],
                ['node_key' => 'ai', 'type' => 'ai_generate', 'position_x' => 280, 'position_y' => 0, 'config' => [
                    'system' => 'You are a concise assistant that triages customer inquiries.',
                    'prompt' => "Summarise this inquiry in one sentence and suggest a priority (low/medium/high):\n\n{{ form.message }}",
                    'store_as' => 'summary',
                ]],
                ['node_key' => 'email', 'type' => 'send_email', 'position_x' => 560, 'position_y' => 0, 'config' => [
                    'to' => 'team@example.com',
                    'subject' => 'New inquiry from {{ form.email }}',
                    'body' => "AI summary:\n\n{{ vars.summary }}\n\n---\nOriginal:\n{{ form.message }}",
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'ai'],
                ['from_node_key' => 'ai', 'to_node_key' => 'email'],
            ],
        ];
    }
}
