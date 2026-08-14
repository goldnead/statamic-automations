# Templates catalog

Templates ship with the addon and are **copied** into a new automation when installed.
Editing the copy never affects the template, and addon updates never silently change your existing flows.

## How to use

1. Open **Automations → Templates** in the CP.
2. Click **Install template** on the card you want.
3. A new disabled automation is created from the template — open it, customize the configuration, then enable.

Or call the API directly:

```bash
POST /cp/automations/api/templates/{handle}/install
```

## Catalog

### `new_lead_notification`

> Email the admin whenever a LeadHub lead is created.

- **Requires**: LeadHub
- **Trigger**: `leadhub.lead_created`
- **Actions**: `send_email`

### `form_submission_to_webhook`

> Forward Statamic form submissions to an external webhook.

- **Requires**: nothing
- **Trigger**: `form_submitted` (configure the form handle)
- **Actions**: `send_webhook`

### `qualified_lead_to_crm`

> Push leads to a CRM destination once they reach status _Qualified_, add a note, and schedule a follow-up.

- **Requires**: LeadHub
- **Trigger**: `leadhub.lead_status_changed` (filter for `Qualified`)
- **Actions**: `send_webhook`, `leadhub.add_note`, `leadhub.create_follow_up`

### `workshop_inquiry_flow`

> Capture workshop inquiries from a form, create a tagged LeadHub lead, notify the team, and schedule a follow-up.

- **Requires**: LeadHub
- **Trigger**: `form_submitted` (configured for `workshop_request`)
- **Actions**: `leadhub.create_or_update_lead`, `leadhub.add_tag`, `send_email`, `leadhub.create_follow_up`

### `lead_magnet_delivery`

> Capture an email through a form, deliver the lead magnet by email, and create a tagged LeadHub lead.

- **Requires**: LeadHub
- **Trigger**: `form_submitted` (configured for `lead_magnet`)
- **Logic**: `filter` (require non-empty email)
- **Actions**: `send_email`, `leadhub.create_or_update_lead`, `leadhub.add_tag`, `add_log_entry`

The only built-in that mails a member of the public rather than your own team,
and the only one that may: the mail is the file they just asked for. Nothing
here subscribes anybody. A nudge, a follow-up or an offer afterwards is
marketing — it needs a subscription and the `marketing.send_email` node from
`goldnead/statamic-marketing`, never a second `send_email` here.

### `follow_up_reminder`

> Email yourself a reminder whenever a LeadHub follow-up becomes due.

- **Requires**: LeadHub
- **Trigger**: `leadhub.lead_follow_up_due` (window: `due_today`)
- **Actions**: `send_email`

### `entry_published_notification`

> Send a webhook (e.g. Slack) whenever an entry in a particular collection is published.

- **Requires**: nothing
- **Trigger**: `entry_published` (configured for the `articles` collection)
- **Actions**: `send_webhook`

### `webhook_failure_alert`

> Email the admin when an outbound webhook keeps failing.

- **Requires**: Webhook Manager
- **Trigger**: `webhook_manager.outbound_failed` (min attempts: 3)
- **Actions**: `send_email`

## Adding your own templates

Templates live as plain PHP arrays in `src/Templates/TemplateRegistry.php`. To add one in your own application:

1. Extend the registry in your service provider — Laravel's container makes it trivial to swap implementations.
2. Or build the automation in the CP, export it as JSON, and ship the JSON file with your starter kit. Use `POST /automations/import` to install it elsewhere.

A future iteration will surface user-contributed templates from `resources/automations/` directly in the Templates screen.
