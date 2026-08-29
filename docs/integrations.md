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

### Three actions, the other direction

| Action | What it does | Handle |
| --- | --- | --- |
| Cancel Booking | Cancels a booking, with a reason everybody involved sees | `cal_com.cancel_booking` |
| Get Free Slots | Reads the free times of an event type | `cal_com.get_slots` |
| Create Booking | Books a slot | `cal_com.create_booking` |

They need a **second credential**, different from the webhook secret above and
kept somewhere else: an API key from **Settings → Developer → API keys**.

```dotenv
STATAMIC_AUTOMATIONS_CALCOM_API_KEY=cal_live_…
```

Without it the three actions refuse and say so, rather than calling into
nothing. The triggers are unaffected; they never needed a key.

#### The header that decides everything

cal.com's API v2 versions **per endpoint**, through the `cal-api-version`
header, and the right version is a different one for each endpoint. There is no
version that fits all of them.

The wrong one does not answer `400`. It answers, measured on 2026-08-29:

| Endpoint | Right version | Wrong version answers |
| --- | --- | --- |
| `/v2/bookings*` | `2024-08-13` | `200`, correct envelope, a different shape inside |
| `/v2/slots` | `2024-09-04` | `404 Cannot GET /v2/slots` |
| `/v2/event-types*` | `2024-06-14` | `404`, or with no header at all `200` in a different shape |

Two of those three are silent. That is why the client carries the version as a
constant next to each operation rather than as one header for all of them, and
why every action demands the field that proves what it claims: a cancellation
is only reported when the booking comes back as `cancelled`, a booking only
when it comes back with a `uid`. You do not have to set any of this; it matters
if you ever add an operation.

#### Running a flow twice

None of the three has an idempotency key, because cal.com offers none.

- **Cancel Booking** is safe. cal.com refuses the second cancellation with a
  `400`, and the action looks at the booking's actual state rather than the
  wording, so the node stays green. What tells the two runs apart is
  `{{ node.cancelled }}`: `true` means this run did it, `false` with
  `{{ node.already_cancelled }}` means an earlier one did. **Hang a
  notification on `cancelled`, not on the node being green**, or the second run
  sends the cancellation mail a second time.

  One case goes red on purpose: the cancellation went out, cal.com carried it
  out, and the answer never came back. The booking is cancelled and there is no
  way from here to tell whether this run did it. Claiming an earlier run would
  be the comfortable answer and the worse half of the mistake, because
  `cancelled` stays `false` and the cancellation mail then goes out in no run at
  all. Note that the engine retries a red node by itself; if you would rather
  keep that red node than lose the notification, set `_retry_attempts` to `0` on
  it.
- **Create Booking** is safe as long as the start time is the same. cal.com
  answers a taken slot with `409` and creates no second booking, so the second
  run goes red. The protection comes from the calendar, not from the API: if
  your flow *computes* the start instead of carrying it, the second run computes
  a different one and books twice. Take the time from what the person picked,
  out of the run's context.

  `{{ node.slot_unavailable }}` says the slot was not to be had. It does **not**
  say a booking exists: cal.com's own message is "already has booking at this
  time **or is not available**", and a start outside the host's availability or
  a wrong time zone gives the same `409` with no booking anywhere.
- **Get Free Slots** reads and changes nothing over at cal.com. It is, however,
  where the one real double-booking in this integration starts. `Get Free Slots`
  into `Create Booking` with `{{ node.first }}` in the start field looks
  harmless and is not: on the second run the first run's slot is taken, this
  node asks again and hands out the *next* one, the `409` never fires, and the
  same person ends up with two appointments. `first` belongs in a mail or in a
  branch, not in a booking node.

#### An empty answer is not proof

`/v2/slots` answers an unknown event type with `{}` and status `200`, the same
answer as a fully booked calendar. A flow with a mistyped or since-deleted event
type ID would therefore suggest nothing, quietly, for months.

**Get Free Slots** cross-checks: when the answer is empty, it asks whether the
event type exists at all. If it does not, the node goes red and says so. If it
does, `{{ node.count }}` is `0` and that is a real statement. The cross-check
only runs on the empty path.

#### Booked, or waiting for confirmation

**Create Booking** returns `{{ node.status }}` as `accepted` or `pending`, and
`{{ node.confirmed }}` as the yes-or-no version of it. Which one you get is
decided by the event type's **confirmation policy** and by nothing else.

This is the one that costs an afternoon: `GET /v2/event-types` does not return
that field, and it is not called `requiresConfirmation`. It lives only in
`GET /v2/event-types/{id}`, under `confirmationPolicy`. Looking in the list,
finding nothing, and concluding that no confirmation is needed is the easy
mistake.

The same thing explains a second surprise: **a `pending` booking does not fire
`BOOKING_CREATED`.** cal.com sends `BOOKING_REQUESTED` for a booking awaiting
confirmation, which is what the **Booking Requested** trigger is for.
`BOOKING_CREATED` arrives once somebody confirms.

A green node means cal.com accepted the request, not that the appointment
stands. Branch on `{{ node.confirmed }}`.

#### Test runs

Cancelling and creating write to somebody else's calendar and send mail, and a
cancellation cannot be undone from here at all. A test run previews both and
sends nothing, unless you deliberately switch on
`automations.test_mode.persist_cal_com_changes`.

Reading free slots is deliberately not covered by that: it changes nothing over
there, and a preview made of invented times would be worth nothing. A test run
really asks. Without an API key it therefore goes red, which is the right
answer for a node that cannot work.

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

**No event type list.** The ID of an event type is a fixed value in the setup of
a flow, not something looked up at runtime, so a node that fetches a list nobody
reads would only sit in the editor. The one place that genuinely needs it, the
cross-check in **Get Free Slots**, fetches it itself.

**No reschedule, confirm or decline.** The API v2 does all three. None of them is
called by a flow today, and a node that nothing calls still has to be maintained
and tested with every change to the client.

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
