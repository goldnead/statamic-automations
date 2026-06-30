# Integrations

Statamic Automations is the **orchestration** layer. It is designed to compose
with two sister addons rather than reimplement what they do:

| Concern | Addon | Role |
| --- | --- | --- |
| Orchestration | **statamic-automations** (this addon) | Triggers, control flow, actions, runs |
| Transport | **statamic-webhook-manager** | Inbound + outbound webhooks: HMAC signing, retries, delivery snapshots, replay |
| Domain | **statamic-leadhub** | Leads, statuses, tags, notes, follow-ups |

Each integration is **optional** and auto-detected at boot. If the sister addon
is not installed, the related nodes simply do not register — nothing breaks.

## How detection works

`IntegrationDetector` checks the class names listed under
`config('automations.integrations.*.detect')`. The first class that exists wins.
You only need to touch this config if you ship a forked package under a
different namespace.

```php
'integrations' => [
    'webhook_manager' => [
        'detect' => [
            'Goldnead\\WebhookManager\\Facades\\WebhookManager',
            'Goldnead\\WebhookManager\\WebhookManager',
        ],
        'inbound_event' => 'Goldnead\\WebhookManager\\Events\\WebhookReceived',
    ],
    'leadhub' => [ /* ... */ ],
],
```

## Webhook Manager

When Webhook Manager is present:

- **Outbound** — the `webhook_manager.send` action delegates delivery to
  Webhook Manager, so you get HMAC signing, retries, dead-letter handling and a
  delivery record you can replay — instead of the fire-and-forget
  `send_webhook` action.
- **Inbound** — the `webhook_received` trigger listens for the configured
  `inbound_event`. The validated request payload, headers and endpoint are
  mapped into the run context as `{{ webhook.payload.* }}`,
  `{{ webhook.headers.* }}` and `{{ webhook.endpoint }}`.

A typical inbound flow (see the **Inbound Webhook → Entry** template):

```
webhook_received (endpoint: orders)
  → throttle (key: {{ webhook.payload.id }})   # drop duplicate deliveries
  → create_entry (collection: orders)
```

If Webhook Manager is **not** installed, use the built-in `send_webhook` action
for simple outbound calls.

## LeadHub

When LeadHub is present, these triggers and actions register automatically:

- Triggers: `leadhub.lead_created`, `leadhub.lead_status_changed`,
  `leadhub.lead_tag_added`, `leadhub.lead_note_added`,
  `leadhub.lead_follow_up_due`.
- Actions: create/update leads, add tags, add notes, create follow-ups.

Set `integrations.leadhub.emit_timeline_events` to write a timeline entry on the
lead whenever an automation modifies it.

## Secrets across integrations

Never embed an API key or signing secret in a node config — it would be
persisted and shown in the CP. Reference a named secret instead:

```php
// config/automations.php
'secrets' => [
    'crm_token' => env('CRM_TOKEN'),
],
```

```
Authorization: Bearer {{ secret.crm_token }}
```

`{{ secret.* }}` resolves from the secret store at run time and is covered by
log redaction.

## AI

The `ai_generate` action calls the Anthropic Claude Messages API. Configure
credentials under `config('automations.ai')` (pulling the key from the
environment) and reference any context value in the prompt:

```
Summarise this inquiry in one sentence: {{ form.message }}
```

The generated text is stored as the node's `text` output and, optionally, under
`{{ vars.* }}`. See the **AI Triage of Inquiries** template.
