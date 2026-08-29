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

## cal.com

The one integration here that is **not** a sister addon and **not** detected.
cal.com is a service, so there is no class for `class_exists` to find, and
nothing to install alongside. The connector brings its own route, its own
signature check and its own guard against a redelivery, and it depends on no
other addon.

Five triggers, one per event cal.com sends about a booking:

| Trigger | cal.com event | Handle |
| --- | --- | --- |
| Booking Created | `BOOKING_CREATED` | `cal_com.booking_created` |
| Booking Requested | `BOOKING_REQUESTED` | `cal_com.booking_requested` |
| Booking Cancelled | `BOOKING_CANCELLED` | `cal_com.booking_cancelled` |
| Booking Rejected | `BOOKING_REJECTED` | `cal_com.booking_rejected` |
| Booking Rescheduled | `BOOKING_RESCHEDULED` | `cal_com.booking_rescheduled` |

### Setting it up

1. In cal.com, open **Settings → Developer → Webhooks** and add a webhook.
2. Subscriber URL: `https://your-site.test/!/automations/cal-com`
   (the prefix comes from `automations.routes.prefix`, the last segment from
   `automations.integrations.cal_com.path`).
3. Select the events you want. Anything this addon has no trigger for is
   answered `202 ignored` rather than retried.
4. Copy the **secret** cal.com generates and put it in your `.env`:

```dotenv
STATAMIC_AUTOMATIONS_CALCOM_SECRET=the-secret-from-cal-com
```

**Without that value the route accepts nothing.** It answers `503`, not `200`.
A webhook endpoint without credentials is a form any stranger can post
bookings into, and those bookings would send mail.

If you run `route:cache`, the path is frozen into the cache. Changing it in the
environment takes a `route:clear`.

### What the checks actually check

- **Signature.** cal.com signs with `x-cal-signature-256`: HMAC-SHA256 over the
  raw request body, keyed with the webhook secret, as lowercase hex with no
  prefix. This addon verifies against the raw bytes and compares with
  `hash_equals`. Note the trap: when no secret is configured **on cal.com's
  side**, cal.com does not omit the header, it sends the literal string
  `no-secret-provided`. An endpoint that only checks whether a header is
  present lets that through.
- **Age.** cal.com sends neither a delivery id nor a timestamp header, so a
  captured, validly signed body would otherwise stay valid forever: anybody with
  it out of a log or a proxy trace could replay it. The `createdAt` inside the
  envelope is covered by the signature, and an envelope older than
  `max_age_minutes` (a day by default) is refused. Keep that value equal to
  `dedupe_minutes`, or you leave a window in which neither guard applies.
- **Redelivery.** cal.com sends again when an answer does not arrive. The pair
  (event, booking `uid`) is remembered for `dedupe_minutes`, and a second
  delivery of the same pair answers `200 duplicate` without starting the flow a
  second time. The pair and not the `uid` alone: the same booking is created,
  rescheduled and cancelled, and all three should run. If starting the flow
  fails, the note is taken back so cal.com's retry still gets through.
- **The cache has to be able to remember.** That last guard lives in the cache.
  With `cache.default` on `null` or `array` it cannot work, so the addon says so
  in the log instead of silently doing nothing.
- **Size and rate.** Bodies over `max_body_bytes` (256 KB) are refused before
  the checksum runs, and the route carries `throttle:120,1`. Both are in the
  config; the route has no session and no CSRF token, because a foreign server
  has neither.

### What a flow gets

The payload is flattened into `booking.*`, so a follow-up node never has to dig:

```
{{ booking.uid }}                 {{ booking.title }}
{{ booking.starts_at }}           {{ booking.ends_at }}
{{ booking.duration_minutes }}    {{ booking.status }}
{{ booking.event_type_slug }}     {{ booking.event_type_title }}
{{ booking.event_type_id }}       {{ booking.price_cent }}
{{ booking.currency }}            {{ booking.notes }}
{{ booking.attendee.name }}       {{ booking.attendee.first_name }}
{{ booking.attendee.email }}      {{ booking.attendee.phone }}
{{ booking.attendee.timezone }}   {{ booking.attendee.language }}
{{ booking.attendee_emails }}     {{ booking.attendees_count }}
{{ booking.organizer.name }}      {{ booking.organizer.email }}
{{ booking.meeting_url }}         {{ booking.cancellation_reason }}
{{ booking.rejection_reason }}    {{ booking.reschedule_reason }}
{{ booking.answers.your_field }}
```

`booking.answers` is everything the booker filled in, keyed by the field, as
printable text. That is where your own booking questions land ("Which choir?",
"Voice part"). `booking.attendee_emails` is one comma-separated line, because
the mail action's `to` is a single text field.

Five things about cal.com's own payload that the flattening straightens out,
and that are worth knowing when you read the raw one:

- `payload.type` is the event type **slug**, `payload.eventTitle` its **title**,
  and `payload.title` is the title of the booking. They become
  `event_type_slug`, `event_type_title` and `title`.
- `language` arrives as an object `{"locale": "de"}` and becomes the string
  `de`. An object printed into a mail reads "Array".
- The time format differs per event (`...Z`, `...+00:00`, `....000Z`) for what
  is the same instant. All become one form, the same one the sibling triggers
  emit, so a condition on `starts_at` matches the same appointment on every
  event.
- `price` is in the smallest unit of the currency. It is called `price_cent`
  here so that nobody prints `9000 EUR` into a mail.
- The attendee's phone number is not on the attendee for every event; it is
  taken from the booking form answer when it is missing.

**`booking.location` is not an address.** For an online event type it holds a
machine identifier like `integrations:daily`, for an on-site one the real
address. It is passed through as it comes. What belongs in a mail is
`booking.meeting_url`.

A **reschedule is not an edit**. cal.com cancels the old booking and creates a
new one with a new `uid`. `booking.uid` is the new booking;
`booking.rescheduled_from_uid` and `booking.rescheduled_from_starts_at` are the
old one. Anything keeping its own record of the appointment has to look it up by
`rescheduled_from_uid`.

The untouched payload stays available as `{{ cal_com.payload.* }}` for the rare
field the flattening does not cover. Be aware that the run context is stored:
attendee mail addresses, phone numbers and the meeting link end up in
`automation_runs.context` and on the run screen. Add anything you would rather
not keep to `automations.security.redact_keys`, or shorten the retention of runs.

### Filtering

Every trigger takes an **event type slug** and an **event type ID**; set either,
or both, in which case both have to match. A site running a free intro call and
a paid lesson through cal.com gets the same webhook for both, and a flow without
a filter sends the paid-lesson mail to somebody who booked the free call.

The slug is the value you can read off your cal.com account and type in without
looking anything up, but it sits in the booking URL and gets changed when
somebody tidies that URL, at which point the filter silently stops matching. The
ID never changes and is the one to use for a flow that should hold for years.
The title is deliberately not a filter: that gets changed whenever it should
read better in a mail.

Anything else you want to narrow on is in the context already, so a **Filter**
node right behind the trigger does it, for example `booking.organizer.email` on
a team account.

### What is deliberately not here

**No actions.** Creating or cancelling a booking through cal.com's API needs an
API key, which is a different credential in a different place, and that decision
has not been made.

**No `MEETING_ENDED` / `MEETING_STARTED`.** cal.com sends those in a different
shape: flat, without the `payload` wrapper, carrying the raw database row rather
than the prepared event, so `user` instead of `organizer` and `id` instead of
`bookingId`. That is a second flattener and a second output schema, not
something that falls out of this one.

**No `RECORDING_READY`.** It carries no full booking, essentially a download
link, and only applies to Cal Video.

**No `BOOKING_PAYMENT_INITIATED`.** cal.com's documentation does not show what
its payload looks like, and a trigger whose fields are guesses falls over on the
first real webhook.

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
