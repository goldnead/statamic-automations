# Changelog

## 2.18.0 (2026-09-08)

### Fixed: an unmigrated install no longer answers HTTP 500

The automations list, dashboard, runs, rules and audit screens used to die with `no such table`
when the addon was installed but its migrations had never run. Each now shows an empty state
naming the tables it is missing and saying to run `php artisan migrate`, and writes the reason
to the log. Every screen names only the tables it actually reads.

Under the `flat_file` driver the definition tables are left out of the check, because nothing
reads them there. Where a screen reaches the definitions through an Eloquent relation rather
than the repository, they are named regardless.

## 2.17.0 (2026-09-07)

### Added: the trigger filters offer what is there instead of demanding a handle

Seven triggers used to require knowing the object's handle and typing it out: the payment
triggers the product, the four funnel triggers the funnel, the marketing triggers the list and
the campaign. All seven were a text field without an option source. They now point at the
existing `OptionSourceRegistry`; `marketing.lists` and `marketing.campaigns` are already
registered there by the marketing addon itself, and `payments.products` and `funnels.funnels` are
newly registered.

The product list comes from the payments catalogue, not from the products table: the catalogue is
the only place that brings the config file, `statamic-products` and `statamic-offers` together. A
picker built on the table alone showed three of six products. Both resolvers are guarded by class
name and return an empty list without the respective sibling addon, never an error.

The funnel trigger's step filter stays a text field: which steps are available depends on the
funnel chosen, and the options endpoint cannot do a dependent list today.

### Fixed: the template picker offered templates it could not show

The template list in the email node queried without a brand filter, while the preview resolved
through the brand-bound resolver. On an installation with several brands the picker therefore
offered other brands' templates, which it could not display and which the node would not have
sent either. Both ends now use the same brand, every failure names its reason and writes a log
line instead of "preview unavailable", and a template that does not render can no longer be
selected.

### Added: a flow's mails can be looked at

An endpoint returns a step's stored mail, rendered with sample data and with placeholders left
standing where there is none. A preview with a step picker shows it in the mails view and on the
node. What actually went out comes from the snapshot layer in `statamic-email-templates`: the
send records the template with its `{{ }}`, never one recipient's resolved text.

### Fixed: an unresolvable template went down silently in the fallback

If a node names a template that cannot be found from its brand, the node's own text has always
gone out instead. The fallback stays correct, but it was silent: a flow can spend months sending
something other than what it says. There is a log line now, throttled per handle, so that a
fan-out writes it once and not a thousand times.

### Changed: `goldnead/statamic-brand-context` from 1.13

The settings page from 2.16.0 does not work reliably under older versions. On an installation
with a single brand, the values of the addons registered last were not applied to the config at
all — `automations` was one of them when this was measured in the playground on 2026-09-07. The
page showed the stored value while the package default was what got read. On top of that, up to
1.12 a second save of the same section deleted the first one's override, without a message. The
permission stays `manage automation settings`, nothing about the handling changes. Anyone who set
settings between 2026-09-06 and this update should check whether they are still there; lost
values do not come back on their own.

## 2.16.0 (2026-09-06)

### Changed: the settings move to the shared suite screen

The page **Automations → Settings** is gone. The same fields now sit under **Settings → Addon
settings**, together with those of the other suite addons. The old address redirects, the
permission `manage automation settings` is unchanged, and a migration carries stored values into
the new table. There is nothing to do beyond `php artisan migrate`.

- **Requires `goldnead/statamic-brand-context` ≥ 1.12.** That package is MIT and was already a
  dependency; it now provides the screen, the validation, the storage and the brand dimension.
  This addon only writes the field list (`Support\Settings::settingsGroups()`) and registers
  itself.
- **The values are brand-scoped from now on.** `automation_settings` had no `brand_id`, so on a
  multi-brand installation two brands shared one setting. In single-brand operation nothing
  changes.
- **`automation_settings` stays for one minor version.** Otherwise anyone rolling back loses
  their settings. Dropping it comes later and announced.
- **The integrations display has moved to the dashboard.** It was never a setting but a
  detection — which sibling addons are installed is Composer's decision.
- Net −1067/+296 lines: the model, the request, two controllers and the Vue page are gone.

## 2.15.5 (2026-09-05)

### Fixed: the shortening fused two words, let punctuation pass as a name, and cleared only one level of brackets

- **Every placeholder now leaves a space behind, not nothing.** That is exactly what the promise
  "it never invents words" hung on, and it was not true:
  `{{if premium}}Premium{{else}}Basis{{/if}}` became `PremiumBasis` — a word no reader ever
  receives. Now `Premium Basis`. For the same reason `Hallo{{ name }}Welt` becomes `Hallo Welt`
  instead of `HalloWelt`: somebody wrote both halves, nobody wrote the compound.
- **If only a punctuation mark is left, that counts as empty.** `{{ x }}.` produced `.`, and
  because a full stop is not empty to PHP, it won against the step's own name — the confirmation
  then read "delete "."?". "Readable" now means: at least one letter or digit.
- **Nested empty brackets are cleared completely.** `A (({{ x }})) B` was left at `A () B`,
  because the cleanup ran only once. It now runs until nothing changes any more.
- **Three comments still described the behaviour before 2.15.4** (the projection, the controller,
  `Edit.vue`) and referred to a method whose own docblock said the opposite by then.

## 2.15.4 (2026-09-05)

### Fixed: the shortening from 2.15.3 threw away the rest of the subject

- **Every placeholder is removed now, instead of cutting at the first one.** 2.15.3 cut before
  the first `{{` — with a German subject that starts with the first name, nothing at all was
  left, and that is not an edge case. `Hallo {{ name }}, willkommen` is now
  `Hallo, willkommen` instead of `Hallo`, and `{{ contact.first_name }} — dein Platz im Kurs`
  is `dein Platz im Kurs` instead of the node key.
- **That makes the short form unambiguous again.** `Hallo {{ name }} Teil 2` and
  `Hallo {{ name }}, willkommen` both produced "Hallo" in 2.15.3 — two mails, one confirmation
  dialogue. The name appears in that dialogue because the reader may have opened the menu on
  the wrong row; a short form that names two rows identically takes exactly that away.
- **Antlers blocks:** the tags go, the text between them stays
  (`Newsletter {{if foo}}Ja{{/if}} Ende` → `Newsletter Ja Ende`). That is what the reader sees
  when the condition holds, and it needs no parser to be right.
- **Only connecting and opening characters are trimmed at the seam, closing ones are not.**
  `„Zitat“ {{ x }}` keeps its closing quotation mark, `Betreff (für {{ x }})` keeps the bracket
  instead of ending on a lone `(`, and a full stop or question mark at the end belongs to the
  author and stays.
- **If nothing is left of the subject, the step's own name is used now.** The "Name" field in
  the add form exists precisely for that. Only after it comes the node key. As long as the
  subject still carries words, the subject wins — it is the line that leads the mail.
- **An incomplete `{{` without closing brackets** no longer reaches the screen with visible
  brackets.
- **`After “:label”` has a German translation** (`Nach „:label“`). In the German CP there was
  English text with English quotation marks there so far, right next to the German
  „…" löschen?.

## 2.15.3 (2026-09-05)

### Fixed: the raw Antlers placeholder appeared in the confirmation before deleting

- **Delete "Zahlung bestätigt, {{ contact.first_name }}"?** The stored mail name is a subject
  template, and the confirmation quoted it verbatim, placeholder included. The name is now cut
  before the first `{{` and finished cleanly — delete "Zahlung bestätigt"? This applies to both
  deletion paths (the row menu through Statamic's action list, and the confirmation from the
  opened mail), which still read identically.
- **Shortened, not resolved.** A subject is written against the contact a run will one day
  have; in the Control Panel there is none. So there is nothing for `Engine\TokenResolver` to
  resolve against, and an invented name would put a sentence on the screen that nobody ever
  receives.
- **The "Mail" column still shows the stored subject, placeholders and all.** There it is the
  mail's own value, not a sentence about it; anyone who wants to read the subject has to be
  able to see it in full somewhere. New is `display_label` next to `label` in the mail list —
  the same line, shortened — and every sentence that calls a mail by name uses that one: the
  delete confirmation, the "After" picker in the add form and the rule sentence "When … happens,
  send … to …".
- **The test for it has four shapes**, because one fixed example subject misses precisely the
  interesting ones: without a placeholder, with one at the end, with several, and with no name
  at all. A name consisting only of a placeholder falls back to the node key.

## 2.15.2 (2026-09-05)

### Fixed: the bulk export checked the keys only when offering, not when running

- **`POST …/activity/steps/actions` accepted any step key.** An unknown one produced HTTP 200
  with a CSV containing only the header row; a mixed selection produced a file of the valid
  half. Exactly what the check in `/actions/list` was meant to prevent — it only sat there. It
  was not reachable through the interface (Statamic asks `/list` first), so this is defence in
  depth. Now a 422 naming the unknown step. A file is the one answer here that nobody reads on
  screen before believing it.
- **`makeJsonTranslationsReachable()` names its residual risk.** `setLoaded([])` also discards
  whatever was injected at runtime through `Lang::addLines()`: groups and JSON files come back
  from disk, `addLines` lines do not. In the playground there is not a single caller for that;
  on somebody else's installation with such a package it would be a silent loss. It is in the
  docblock now, together with the narrower workaround.

## 2.15.1 (2026-09-05)

### Fixed: versions for deletions that never happened — and German server strings

- **A rejected bulk deletion wrote a version regardless.** `write()` created the snapshot before
  the attempt, not after success. `runAction` is the only write path where the client sends a
  list of ids it picked itself — a stale table (a second tab, a colleague, an undo) is the
  ordinary case there, not the exception. Four rejected calls left four entries "Removed mails
  from the list" that had removed nothing; the history keeps only 25, so enough of them push the
  real ones out. The check now runs **before** the snapshot.
- **`actionList` did not validate the selection.** A selection containing an id that does not
  exist was offered "delete mail" — an action guaranteed to fail when run.
- **Server-side `__()` did not reach the addon's own dictionary.** Laravel memoises a locale's
  merged JSON translations on first access; an addon that registers its path afterwards is not
  in there. Measured in the playground: the loader offered 1831 keys, the translator held 1725 —
  the missing 106 were exactly this addon's. That stayed invisible as long as every string was
  translated a second time in the browser; as soon as one carries a value (`:count`) that is no
  longer possible, and the bulk button stood there in English. The memo is now discarded after
  the path is registered.
- **The bulk button and the confirmation count along:** "delete 2 mails" instead of "delete
  mail", and a single one is named: delete "Angekommen?"?
- **Multi-select on the steps as well** (F20 is at four of four with that). The action behind it
  is the bulk export: `node` now takes a list, and the new routes
  `POST …/activity/steps/actions{,/list}` return the CSV of the selected steps. The single
  export has moved from the row menu into the same action — one code path instead of two.
- **Moving a row resets the sort.** Anyone who sorted the mails by "sent" and then chose "move
  up" saw the row jump seemingly at random: what moves is the position in the flow, while the
  sort was by something else. The move now resets the table to "position".
- **One deletion, one wording.** The same act came with two phrasings from the table and from
  the opened mail. Both now say literally the same thing.
- Empty cells carry their column's font size; before this the type jumped between 14px and
  11.2px within one column, depending on whether the row had a value.

## 2.15.0 (2026-09-05)

### Changed: steps and mails are Statamic tables now

Both views were stacked cards with hand-built internals: no column headings, no sorting, no
multi-select, no "…" menu — and five records filled the screen (F20, F22). They now run through
the same `Listing` component as the "Log" and "In the flow" tabs beside them, in its client-side
mode (`:items`): the figures are already in the Inertia payload, so no new route is needed for it.

- **Activity → Steps** is a table of seven columns: position, step, reached, share, passed,
  failed, went no further. What used to sit as a sentence under the bar ("3 passed · 1 failed
  here") is sortable and readable underneath each other with that. The blue progress bar is
  gone; the percentage it drew has a column of its own. One "…" menu per row with "view in the
  log" (switches the tab and sets the step filter) and "export this step".
- **Mails** is a table of six columns: position, mail, reference, sent, condition, runs in
  between. The counters still sit as a narrow row above it, but without the four colours — they
  are figures for the whole automation, not for one row. Only "failed" stays red, and only when
  there are any.
- **Multi-select on the mails, with real effect.** New: `POST …/mail-list/actions/list` and
  `POST …/mail-list/actions` — Statamic's action contract. That gives the table a selection
  column, and the selection can be deleted, through the same `ChainEditor` and with the same
  version snapshot as a single mail. The column only appears if the list may actually be changed
  (a linear flow, the "edit automations" permission, no unsaved canvas changes).
- **Up/down and delete have moved into the "…" menu.** No drag: Statamic's listing hides the
  selection column as soon as `reorderable` is on, and permanently trading multi-select for
  dragging would be the wrong direction for a list this short.
- **The steps deliberately have no selection column:** those are counted rows, not records, and
  there is nothing a selection of them could do.
- The mails view now uses `max-w-page` like every other listing screen in the addon, instead of a
  width of its own.

## 2.14.2 (2026-09-05)

### Fixed: the header, the mails tab, other packages' translation keys

- **The automation's header repeated the addon's name** (icon, word and slash before the title).
  No core screen states in its title which area one is in; that is gone (F21). The active badge
  hung in the middle and now sits on the right with the actions. Reaching the name itself did not
  work cleanly: the name field keeps a minimum width so that an empty name stays clickable, and
  that is exactly where the gap opened.
- **The mails tab ran in English** next to German pills (E01). 38 keys added to
  `resources/lang/de.json`, the composed waiting-time sentences with their placeholders included.
- **Two "Tools" sections underneath each other.** `section(__('Tools'))` sent the translated
  value in the German CP, and to Statamic that is a different key from its own `Tools`. The
  section is named by the key now.
- **No more occupying other packages' translation keys.** Every package's JSON translations end
  up in one dictionary, without a namespace: the last one to register wins for the whole Control
  Panel. Three of the keys added yesterday reached into other packages' entries, one of them with
  damage: `Disabled` would have replaced Statamic's "Deaktiviert" with "Abgeschaltet" everywhere.
  `Step` and `Disabled` are gone, and for `days` the source string was made unambiguous following
  the house rule (the delay units are called `Minutes`/`Hours`/`Days`), instead of overwriting
  `statamic-marketing`'s translation. `TranslationKeyOwnershipTest` guards that.

Internal: the return type of `SequenceOptOut::sequencesFor()` is annotated as a shaped `stdClass`,
the way PHPStan infers it; `tests/Fakes/insights-contracts.php` has been through Pint
(declarations unchanged).

- **The CI's MySQL job is green.** It failed on the order of the time-series buckets, which
  `TableMetric::bucketed()` in `statamic-insights` grouped without an `ORDER BY`; MySQL 8 returns
  the groups in the order it met them. Fixed in insights 1.2.1, and the byte-for-byte copy
  `tests/Fakes/insights-table-metric.php` has been brought along.
- **Larastan without a remainder.** `view('statamic-automations::sequence-opt-out')` did not count
  as a `view-string` to Larastan, because it does not resolve package namespaces (`viewDirectories`
  does not help there). The finding is excluded in `phpstan.neon` with a reason; the template
  exists and is rendered in the suite.

## 2.14.1 (2026-09-03)

### Fixed: the interface, and a guard that had been red for three weeks

- **The inspector panel's footer rendered empty for trigger nodes** — a separator line under
  nothing. The `v-if` now sits on the `<footer>`.
- The icon `list-bullets` does not exist (now `list-ul`).
- The dropdown's own dots trigger is gone: core renders it itself, and the custom one was merely
  one size too large.
- `Button variant="danger"` in the inspector moved into the `…` menu.
- Three chips without `pill`, but without `size="sm"` either: they qualify a name and are not a
  status. Round next to a square status badge would be worse.

**The icon guard had been red since 2026-08-15** and told nobody: its regex matched `name="…"` on
every tag, and the assertion sat inside the loop — on the first false match it aborted and never
reached `list-bullets`. Two separate regexes now, and one collected assertion at the end.

`node-icon.test` was in the wrong runner: through `@goldnead/flow-canvas` it pulls in a `.vue` that
bare Node cannot load. The vitest configuration inlines that package for exactly this reason — the
file was moved, the assertions unchanged.

## 2.14.0 (2026-08-29)

### Added: this addon's figures appear in Insights

From 1.1.0 onwards `statamic-insights` is no longer a revenue report but the family's reporting
layer: every addon registers what it can count, and gets the period, the comparison against the
previous period, the chart, the splits and two finished screens for it.

The coupling is voluntary in **both** directions. Without Insights nothing is missing here;
without this addon only its group is missing there. `suggest`, never `require`.

Every figure keeps to the contract's house rules: **null is not zero** (a rate without a
denominator has no answer and shows no 0 %), `available()` decides about existence and never about
the data, gaps in the series are filled by Insights and not by the metric, and a filter a figure
does not understand is ignored rather than turned into an error.

Five figures: runs, failures, success rate, median run time, sequence opt-outs.

**The success rate counts only runs that have a verdict.** Anyone still waiting in a delay is
neither a success nor a failure and stays out of it. That is a named departure from the cohort
rule and is therefore stated in the tile's description.

The sequence opt-outs are computed on a second table and inherit the brand condition with it.

### Fixed: a figure counts only the active brand now

While building this connection, that question got four different answers across the family, and
side by side on one screen that is worse than no answer at all: one tile showed three other
brands' revenue while the tile beside it filtered correctly. The rule now lives once in
`TableMetric::brandScoped()`, as a transcription of `BrandScope::apply()`; all that is named here
is the column, and the figure, the chart and every split narrow together.

If no brand is selected, the tile reads **0 and stays put**. A reader understands a zero; a tile
that has disappeared goes unnoticed.

## 2.13.0 (2026-08-29)

### Added: three cal.com actions, the return direction to the five triggers

Since 2.12.0, five cal.com events arrive in the editor. What a flow could do in response was
report. From now on it can act: **cancel a booking**, **fetch free slots**, **create a booking**.

That makes a rescheduling run through without a manual step. Somebody cancels, the flow fetches
the event type's free slots, sends three proposals, and whatever the customer picks gets booked.

**What is not included:** a "fetch event types" node. An event type's identifier is a fixed value
in a flow's setup and not something looked up at runtime. The only place that really needs it is
the counter-check in "fetch free slots", and that fetches it itself. The API can also reschedule,
confirm and decline; today no flow calls that.

### A second key, in a different place

The actions need an API key, and that is not the webhook secret from 2.12.0. The key is in cal.com
under Settings, Developer, API keys:

```dotenv
STATAMIC_AUTOMATIONS_CALCOM_API_KEY=cal_live_…
```

Without it the three actions do nothing and say so, instead of calling into the void. The triggers
keep running unaffected by that, they never needed a key.

### The header everything hangs on

cal.com's API v2 versions **per endpoint**, through `cal-api-version`, and the correct version is a
different one for each endpoint. There is none that fits all of them. With the wrong one, cal.com
does not answer with a 400 but (measured on 2026-08-29):

| Endpoint | Correct version | With the wrong version |
| --- | --- | --- |
| `/v2/bookings*` | `2024-08-13` | 200, correct envelope, a different shape inside it |
| `/v2/slots` | `2024-09-04` | 404 `Cannot GET /v2/slots` |
| `/v2/event-types*` | `2024-06-14` | 404, and without the header a 200 in a different shape |

Two of three are silent. The client therefore carries the version as a constant next to each
operation rather than as one shared header, and every action requires the field that evidences its
claim: a cancellation only counts as a cancellation once the booking comes back as `cancelled`, a
booking only counts as a booking once it has an identifier. There is nothing to configure about
this. It matters when somebody adds an operation.

### What a duplicate run does

None of the three actions has an idempotency key, because cal.com offers none.

**Cancelling is harmless.** cal.com refuses the second cancellation with a 400, and the action then
looks up the booking's state instead of interpreting the wording. The node stays green. What
distinguishes the two runs is `{{ node.cancelled }}`: `true` means "this run did it", `false`
together with `{{ node.already_cancelled }}` means "an earlier one did". A notification belongs on
`cancelled` and not on the node being green, otherwise the cancellation mail goes out a second time
on the second run.

One case deliberately goes red: the cancellation went out, cal.com carried it out, and the answer
did not come back. The booking is cancelled, and from here there is no way to tell whether this run
did it. Claiming an earlier run would be the convenient answer and the worse half of the mistake:
`cancelled` would stay `false`, and the cancellation mail would then go out on **no** run at all.
The flow engine retries a red node on its own; anyone who prefers the red node to losing the
notification sets `_retry_attempts` to 0 there.

**Creating is harmless as long as the time is the same.** cal.com answers a taken slot with a 409
and creates no second booking. The protection comes from the calendar and not from the API, and
from that follows the one building rule: the time has to come from outside. Anyone who has the flow
compute it gets a different one on the second run, no conflict and a second booking. What the
customer chose belongs in the run's context.

`{{ node.slot_unavailable }}` says the time was not available. It does **not** say that a booking
exists: cal.com's own message reads "already has booking at this time **or is not available**", and
a time outside the availability, or a wrong timezone, produces the same 409 with no booking at all.

**Fetching free slots** only reads and changes nothing at cal.com. It is, however, where this
integration's only real double booking begins. "Fetch free slots" into "create booking" with
`{{ node.first }}` in the start field looks harmless and is not: on the second run the first run's
time is taken, the node asks again and hands out the **next** one, the 409 never applies, and the
same person has two bookings. `first` belongs in a mail or in a branch, not in a creating node.

### Empty is not the same as empty at cal.com

`/v2/slots` answers an **unknown** event type with `{}` and status 200, that is, exactly as it
answers a fully booked calendar. A flow with a mistyped or since-deleted identifier would therefore
silently propose nothing, for months, without anything looking broken.

"Fetch free slots" does the counter-check: if nothing comes back, the action asks whether the event
type exists at all. If it does not, the node goes red and says why. If it does, `{{ node.count }}`
is 0, and that is a real answer. The counter-check runs only on the empty path.

### Booked, or awaiting confirmation

"Create booking" returns `{{ node.status }}` as `accepted` or `pending` and `{{ node.confirmed }}`
as the yes/no version of that. Which of the two it is depends solely on the event type's
confirmation setting.

This is the part that costs an afternoon: `GET /v2/event-types` does **not** hand out that field,
and it is not called `requiresConfirmation` either. It is only in `GET /v2/event-types/{id}`, under
`confirmationPolicy`. Anyone looking in the list finds nothing and reads the absence as "no
confirmation needed".

A second surprise hangs on that: **a `pending` booking does not fire `BOOKING_CREATED`.** For a
booking awaiting confirmation, cal.com sends `BOOKING_REQUESTED`, and the "Booking Requested"
trigger has existed for that since 2.12.0. `BOOKING_CREATED` only arrives once somebody confirms.

A green node therefore means "cal.com accepted it" and not "the booking stands". Anyone relying on
a standing booking branches on `{{ node.confirmed }}`.

### A test run cancels nothing and creates nothing

Both write into other people's calendars and send mail, and a cancellation cannot be taken back
from here at all. A test run shows what it would send, and sends nothing unless
`automations.test_mode.persist_cal_com_changes` is explicitly on.

Reading free slots deliberately does not fall under that: it changes nothing over there, and a
preview made of invented times would be worth nothing. A test run really asks. Without a key it
therefore goes red, and that is the right answer for a node that cannot do its work.

## 2.12.0 (2026-08-29)

### Added: VocalFlow in the flow editor, seven triggers and two actions

VocalFlow is the system the coaching sessions take place in. Until now it ran alongside the flows:
a session was held, a task assigned, a report published, and whatever was supposed to happen
afterwards happened by hand. From now on both are in the editor, in both directions.

**Seven triggers come in.** Six for the events VocalFlow sends through its webhook channel: session
**created** and **completed**, task **created**, **updated**, **assigned** and **deleted**. The
seventh is the **published session**, and it arrives through an endpoint of its own, because
VocalFlow serves it differently.

The session triggers can be filtered by session type, through the slug or the identifier, and by
state. The state filter is not an accessory. "Session created" does not mean "is scheduled" at
VocalFlow: a new record's default value is `draft`, and the import of legacy sessions creates them
directly as `completed`. A flow "send the preparation material" without that filter mails every
student once per legacy lesson at the next import.

The task triggers filter by state and urgency. The task type would be the obvious third axis and
deliberately is not one: VocalFlow only puts it into the payload on "assigned", a filter on it
would fail silently on the others, and a filter that fails silently is the worst kind. The flow
then simply never runs, and nobody goes looking for it.

**Two actions go out:** **create a student** and **credit them a package**. Those are the two steps
that really occur during onboarding. VocalFlow's partner API can do more, and the rest is
deliberately not built: a node nothing calls today still sits in the editor and wants to be tested
along with every change.

Crediting a package is not repeatable by itself, unlike creating a student. That is what the
**idempotency key** field is for, and it stays empty until somebody fills it. What belongs in it is
the value that names the purchase: an order number, a payment identifier. Deriving one from the
payload would be convenient and wrong, because it would always be the same for the same student
with the same package and would therefore swallow the second genuine purchase.

### The signature works differently here than at cal.com

VocalFlow does not sign the bytes it sends but a canonically re-encoded version of the payload. The
bytes on the wire escape slashes and umlauts differently from that. A receiver checking over the
raw body, as with cal.com, would therefore reject **every genuine delivery** as soon as a `/` or an
`ö` appears anywhere in the payload, and both are always present in a VocalFlow payload. The error
would look like a wrongly entered secret and would be searched for in exactly that place.

This integration therefore reproduces the procedure. What is compared is the payload's content, not
its spelling. The order of the keys still counts.

### Four values to set up

Enter two addresses at VocalFlow, `https://your-site.com/!/automations/vocalflow` for the events
and `https://your-site.com/!/automations/vocalflow/session-published` for the published session.
Plus four environment variables:

- `STATAMIC_AUTOMATIONS_VOCALFLOW_SECRET`: the event subscription's secret
- `STATAMIC_AUTOMATIONS_VOCALFLOW_PUBLICATION_SECRET`: the second address's token
- `STATAMIC_AUTOMATIONS_VOCALFLOW_PARTNER_URL` and `_PARTNER_SECRET`: for the two actions

**Without these values the respective route accepts nothing and the actions do nothing.** The
routes are then not open but answer with a 503. An integration without credentials does not call
into the void and is not a form any stranger can write sessions into.

Both routes have a barrier against duplicate delivery. On the event channel it hangs on the
fingerprint of the signed payload and not on the case's identifier, and that is the difference from
cal.com: a booking is created once and cancelled once, whereas a task is genuinely updated several
times. Barring on the task identifier would discard the second genuine update, and the flow waiting
for "the task is done now" would never run.

### What is missing, and why

`session.updated` exists at VocalFlow and has no trigger here. It would be the only event that
"session rescheduled" or "session cancelled" could be built on today. It was not on the list this
integration was built against, and a handle is final: giving one out is not a small thing to take
along in passing. Anyone who needs it should say so.

Of the webhook channel's six events, two actually arrive from VocalFlow today, `session.created`
and `task.assigned`. For the rest the sender is missing on VocalFlow's side. All the triggers are
in the editor regardless: the name is the contract, and whoever closes the gap over there should
find the trigger here rather than start missing it then.

## 2.11.0 (2026-08-29)

### Added: cal.com in the flow editor, five triggers

Anyone taking appointments through cal.com could do nothing with them here so far. A booking came
in, and whatever was supposed to happen afterwards happened by hand: the preparation mail, the CRM
entry, the message to one's own team. From now on there are five triggers in the editor, one per
event cal.com sends about a booking: **created**, **requested**, **cancelled**, **rejected**,
**rescheduled**.

All five can be filtered by **event type**, either through the slug or through the number. That is
not an accessory. A business runs several event types side by side at cal.com, and a free first
conversation and a paid lesson are different cases with different mails. Both fire the same
webhook. A flow without that filter sends the invoice mail to somebody who booked a first
conversation.

Two fields, because both have their drawback. The slug can be read off in the cal.com account and
entered without looking anything up, but it is part of the booking URL and gets changed when
somebody wants a prettier URL; a filter hanging on it then fails silently. The number never
changes, but it is nowhere anybody simply reads it off. The title is deliberately not a filter
axis.

### The integration hangs on nothing

cal.com is not a sibling addon but a service. The integration therefore brings its own route, its
own signature check and its own protection against duplicate delivery. No second addon is needed to
use it.

There is one thing to set up: enter the address `https://your-site.com/!/automations/cal-com` at
cal.com as a webhook and store the secret cal.com shows you as
`STATAMIC_AUTOMATIONS_CALCOM_SECRET`.

**Without that secret the route accepts nothing.** It is then not open but answers with a 503. An
integration without credentials does nothing rather than accepting everything: an open POST address
that starts flows would otherwise be a form any stranger can write bookings into, and those
bookings send mail.

The signature is checked over the **raw** request body, before anything is decoded, and the
comparison runs in constant time. There is a cal.com peculiarity in this that is easy to miss: if
no secret is set on cal.com's side, the signature header is not absent but contains the literal
`no-secret-provided`. Anyone who only checks whether a header is there lets that through.

Plus three barriers no secret replaces. cal.com puts neither a delivery identifier nor a timestamp
in the headers, so a validly signed body captured once would stay valid for ever; anyone who has it
from a log could trigger the flow arbitrarily often later on. The timestamp in the body is signed
along with it, and an envelope older than a day is rejected. Bodies over 256 KB are rejected before
the checksum runs over them. And the route carries a limit of 120 requests per minute, against
somebody who knows the address and calls it in a loop without a secret. All three values are in the
configuration.

### The same booking twice starts the flow once

cal.com delivers again when an answer does not arrive. The pair of event and booking `uid` is
therefore held for a day; if it arrives a second time, it is answered without starting the flow
again. The pair and not the `uid` alone: the same booking is created, rescheduled and cancelled,
and all three are meant to run.

Two things that easily go wrong about this are explicitly handled. If starting the flow fails, for
instance because the queue is momentarily unreachable, the claim is withdrawn. Without that the
booking would be lost: the claim would stand, cal.com's retry would run into "seen already", and
the flow would never start. And the barrier hangs on the cache. If `cache.default` is `null` or
`array`, it cannot work, and the addon says so in the log instead of silently doing nothing at all.
An integration that reports success and does nothing is the most stubborn kind of defect.

### What a following node receives

cal.com's payload is deeply nested and carries around forty fields, most of them for running a
calendar app. The trigger lays out flat the selection a flow really depends on: `booking.uid`,
`booking.title`, `booking.starts_at`, `booking.ends_at`, `booking.duration_minutes`,
`booking.status`, `booking.event_type_slug`, `booking.event_type_title`, `booking.price_cent` with
`booking.currency`, `booking.notes`, `booking.attendee.*`, `booking.organizer.*`,
`booking.meeting_url` and the reason for a cancellation, a rejection or a reschedule. Plus
`booking.answers` with everything answered in the booking form, including custom questions such as
"Which choir do you sing in?", and `booking.attendee_emails` as one string for the mail action's
`to` field, which takes no list. The unchanged payload sits next to it under `cal_com.payload`, for
the rare case this selection does not cover.

Five cal.com peculiarities are straightened out along the way, because otherwise they show up in
production rather than beforehand. `payload.type` is the event type's slug and not its title; the
title is in `payload.eventTitle`, and `payload.title` is a third thing again, namely the booking's
title. `language` arrives as an object and is turned into a string, otherwise the mail says
"Array". The time format alternates per event between three shapes that all mean the same instant
in UTC; one shape arrives here, the same one as with the sibling triggers, otherwise a condition
would find the same appointment on one event and not on the other. The price is in the smallest
currency unit and is therefore called `price_cent`, so that nobody writes "9000 EUR" into a mail.
And the booker's phone number is not on the attendee in every event but in the answer to the form
field; it is fetched from there instead of offering a field that never carries anything.

One field stays raw, and deliberately so: **`booking.location` is not a location.** For a video
appointment it holds a machine identifier such as `integrations:daily`, for an on-site appointment
the real address. What belongs in a mail is `booking.meeting_url`.

**A reschedule is not a changed booking at cal.com but a new one.** The old one is cancelled, the
new one gets a `uid` of its own. `booking.uid` is therefore the new booking, and
`booking.rescheduled_from_uid` and `booking.rescheduled_from_starts_at` are the old one. Anyone
tracking the appointment in a system of their own looks it up through the second field.

### What is deliberately missing

**Actions.** Creating or cancelling an appointment through cal.com needs an API key, and that is a
different kind of credential in a different place. That decision is still open, so for now there
are only triggers.

**`MEETING_ENDED` and `MEETING_STARTED`.** cal.com sends both in a different shape: flat, without
the `payload` envelope, and with the raw database row instead of the prepared appointment, that is,
`user` instead of `organizer` and `id` instead of `bookingId`. That is a second flattener and a
second output schema, not a by-catch.

**`RECORDING_READY`.** Carries no complete booking but essentially a download link, and only
applies to Cal Video. That too would be an output schema of its own.

**`BOOKING_PAYMENT_INITIATED`.** cal.com's documentation does not establish what shape the payload
has. A trigger whose fields are guessed falls over at the first real webhook.

## 2.10.0 (2026-08-29)

### Added: sixteen triggers and four actions for the commerce addons

Four sibling addons fire nineteen events between them. Three of those had a trigger node, the
remaining sixteen fired into the void. Anyone who wanted somebody notified when an access is
revoked, or a cancelled payment plan handled differently from a finished one, had to write the
listener themselves. That is exactly the work this addon is supposed to take off their hands.

**Payments** (with `goldnead/statamic-payments`), six new triggers: refund, subscription started,
subscription renewed, subscription cancelled, subscription ended, subscription start failed. All
filterable by product, like their three siblings.

Two distinctions are in there that matter in a flow. "Cancelled" and "ended" are not the same
thing: one is somebody leaving, the other somebody who has paid the last instalment, and one flow
for both sends "sorry to see you go" to a customer who has just finished paying. And the refund
carries separately how much went back this time and whether that means everything is back. Only the
second of those may trigger a revocation of access, which is why there is a "full refunds only"
filter right on the trigger.

"Subscription start failed" is the case a business otherwise only learns about when the customer
writes in: the money is there, the agreement behind it does not exist. What belongs behind it is a
notification to a person, not a customer mail.

**Entitlements** (with `goldnead/statamic-entitlements`), five new triggers: access granted,
revoked, expired, renewed, awaiting confirmation. Filterable by product and by source. The same
course won through an opt-in and the same course bought are two different facts and deserve two
different mails.

"Access revoked" carries the reason and the actor along. A chargeback a webhook processed and a
refund a person approved are the same database row and very different facts.

Plus two actions: **grant access** and **revoke access**. Both tolerate a second run. An access is
unique over (subject, product, source, source reference), the addon holds that combination with a
unique index, and a second run with the same values returns the existing access instead of creating
a second one.

The result carries three values, because "the call worked" and "this person has access" are two
different facts. `grants_access` answers the second one. `created` only says whether this run wrote
the row, which is narrower than it looks: a confirmed double sign-up unlocks an existing access
without writing anything. Anyone who wants to send a welcome mail exactly once therefore hangs it
on the "access granted" trigger, which the addon fires exactly once per state change.

A revoked access stays revoked, deliberately, so that a redelivered webhook cannot undo a refund. A
second granting run does not change that, and that is why **the action colours its node red in this
case** instead of reporting success. Otherwise the flow would carry on contentedly about a person
who has no access. An access whose start date is still in the future is not an error and says so
through `provisional`.

"Revoke access" revokes every access the subject holds for this product, not the first one found.
`revoked` says how many this run actually changed, `matched` how many rows there are at all.

**Booking** (with `goldnead/statamic-booking`), three new triggers: booked, cancelled, rescheduled.
Filterable by endpoint, and that filter is no ornament: a website runs several endpoints side by
side, a free conversation and a paid lesson, and all of them fire the same three events.

"Rescheduled" is the only one of the three that can repeat. The booking addon writes and reports a
reschedule without checking whether anything changed, so a redelivered reschedule fires a second
time. If something expensive sits behind it, a deduplication belongs in front. That is stated on the
node itself as well.

**Invoices** (with `goldnead/statamic-invoices`), two new triggers: invoice issued, credit note
issued. The credit note carries both documents, because a credit note read on its own says nothing
about what it reverses.

Plus two actions: **issue an invoice** and **issue a credit note**. Both tolerate a second run as
well, held by a unique index on (payment, kind) in the invoicing addon. And here too `created` is
the field a following step should hang on: the action also succeeds when it merely returns the
document that was already there.

The credit note always reverses the whole invoice, regardless of how much money actually went back.
On a partial refund it is the wrong document. It belongs behind a condition on a full refund, or
behind the payments trigger with that filter switched on.

### What is deliberately not included

**No refund action.** `statamic-payments` cannot trigger a refund at the payment provider; it can
only record what somebody did in the provider's dashboard. An action called "issue a refund" would
therefore move no money, but would write the refunded amount and fire the refund event, whereupon
the invoicing addon issues a credit note for money that never went back. As long as the addon
offers no real refund, there is none here.

**No booking actions.** The booking addon offers no outward way to create, reschedule or cancel a
booking; its only public method takes a provider webhook. Writing into the table directly would
bypass its uniqueness key and would fire none of its events. An action that silently does the wrong
thing is worse than none.

### Without the sibling addons nothing changes

All the new nodes hang on the detection as before: if the respective addon is not installed, no node
appears in the library and no listener is registered. An installation without `statamic-booking`
behaves exactly as before. That is now held in place by a test as well, together with the case of an
action failing: it colours its node red and ends the run as failed, instead of throwing an exception
into a queue worker nobody looks at.

## 2.9.0 — 2026-08-25

### What's new

- **Trigger `payments.checkout_abandoned`.** Somebody started a checkout and did not finish it.
  Requires `statamic-payments` 1.7, which does the once-only claim and the sweep; this side is the
  trigger, filterable by product like its two siblings.

  A sequence built on it should end on `payments.paid` — a payment arriving afterwards clears the
  claim on the other side, and that is the honest signal that they bought it.

  **A mail step on this trigger is a consent question**, not a configuration one: the address on an
  unfinished checkout was given to complete a purchase. Put the suppression list in front of the send.

## 2.8.0 — 2026-08-25

### Added — six triggers for funnel and payment events

Both sibling addons had been firing these for a long time, and nobody could hear them: there was no
trigger node for it. "Send the course once the payment goes through" needed a hand-written listener.

- **Funnels** (with `goldnead/statamic-funnels`): step entered, form submitted, offer accepted,
  funnel completed. Filterable by funnel; "step entered" additionally by step, because otherwise
  that trigger fires on every page view.
- **Payments** (with `goldnead/statamic-payments`): paid, failed. Filterable by product. Both
  exactly once per payment, no matter how often the provider delivers.

"Offer accepted" fires **after** the payment, not on the click.

Registered only when the respective sibling is installed, as with LeadHub.

### Changed

- The figures strip on a node card now takes a list from the host
  (`goldnead/statamic-flow-canvas` ^1.1), instead of bringing three fixed, English-named figures
  with it. The old shape is still drawn unchanged.

## 2.7.1 — 2026-08-22

### Fixed — the sequence opt-out ran into a 404 in multi-brand operation

The page's brand comes from the automation, but the subscription behind it is
brand-scoped itself. As soon as the two diverged, the page never found the
token and answered 404 — which looked like "this token does not exist" but was
in fact the fail-closed separation. On the command line, where no brand is
active at all, the same query always failed.

The token is now read without a brand scope. It addresses exactly one row
across all brands, which is what makes that safe; the check that the
subscription and the sequence belong together therefore sits explicitly in the
controller — where both are known. Without it, one brand's token could trigger
an opt-out at another.

Found by checking against the running system, not by a test: in a single-brand
installation the case does not occur.

## 2.7.0 — 2026-08-22

### Added — leaving one sequence without unsubscribing from everything

Up to here it was all or nothing. Unsubscribing from a list does stop running
sequences too — the send node checks before every step whether a subscription
still exists — but it also costs that person the newsletter. Anyone who does
not want to read a five-part welcome series to the end but is otherwise happy
to get mail had no choice except the one that loses them entirely.

New is the intermediate step: a row in `automation_opt_outs` means "this person
wants nothing more from this automation". No more and no less — the list
subscription stays untouched.

**It is checked at two points, and both are necessary.** In the
`EnrollmentGate`, so that an opt-out also applies to a later second run;
otherwise somebody would have left the welcome series and would get it again on
the next occasion. And before every send step, because a sequence waits for days
between the mails: whoever opts out on day 3 must not receive mail 4, and
between the waits nothing runs except this node.

The public page separates showing from acting, like the double opt-in, and for
the same reason: a mail server's link scanner calls every link in a mail before
the person even sees it. A GET that already removes somebody would throw people
out of sequences who never clicked. The way back is on the same page, so that an
accidental opt-out is not final.

### Changed — the context now knows which automation it belongs to

`WorkflowRunner` puts `_automation` into the context before the graph runs. The
context used to be pure payload; a node could not know what it is part of. The
send node needs exactly that in order to ask "does this person still want this
sequence?" — and a run resumed after two days needs it just as much as a fresh
one, which is why it sits in `walk()` and not in the three entry points above
it.

### Notes

The route parameter is called `sequence`, not `automation`. The latter would be
the name this addon would bind if it ever binds one — an unbound parameter with
exactly that name is a trap for the next person who adds a binding.
`RouteParameterCollisionTest` holds that in place.

## 2.6.1 — 2026-08-15

### Fixed — `config:cache` would have frozen the settings

`config:cache` boots the application fully and then writes the resolved config
tree to disk. The stored overrides ended up in there, and a baked-in override
outlives the row it came from: a deleted setting would have kept working until
the next `config:clear`. Worse, the next boot would have read the baked-in file
as "the shipped default" — a value reset to the file's value would then have
been stored as a row instead of deleted, that is, exactly the property this
class promises.

Nothing is applied during the cache build any more. The cached file carries the
file values, and every process lays its overrides on top at its own boot.

The same trap was in the two addons that adopted this construction; there it
was fixed on 2026-08-15. **Here, in the original, it was still open** — found
while writing the README against the code, not by a test. A test that fails
without the fix now holds it in place.

### Docs — the README describes what the addon does again

Added: the activity view, an automation's mails on the contact timeline, the
settings editable in the Control Panel (undocumented since v2.3.0),
`timeline.enabled` and `send_email.refuse_marketing_recipients` in the config
table, and a first data protection section (`subject_key`, run contexts,
timeline entries, and how to delete them).

Corrected: the line about `runs.prune_after_days` said that `null` switches the
pruning off, without mentioning that the field in the Control Panel
deliberately does not go below 1. The fixed statement "408 PHP tests / 141 JS
tests" is gone; a figure in a README is maintenance debt.

## 2.6.0 — 2026-08-15

### Added — the activity view

An automation showed whether it runs, but not **what it does**. `RunStats`
supplied five figures for the whole thing, `automation_node_runs` was evaluated
nowhere, and there was no period filter at all.

The builder gets a third view next to the flow and the mails:

- **Figures on the node**, right on the canvas. A node without runs shows
  nothing, not a zero — a fresh automation should not look like a broken one.
- **A funnel with a period** (7/30/90 days or everything), which shows **where**
  people get stuck, not only how many.
- **A log** with filters by step, result and period, genuinely paginated
  server-side, plus a CSV export of the same selection.
- **Contacts in the flow**: who is in it right now, since when and at which
  step. Runs without a person (a scheduled run, a webhook without an address)
  are counted rather than silently swallowed.

For that, `automation_node_runs` now carries `automation_uuid` and `is_test`.
Both sit on the parent run and are decided when it is created; they are copied
because `is_test` is needed in the **filter**, not for labelling — otherwise
every figure would still need the JOIN. The same reason `brand_id` sits on the
child tables.

### Fixed — the figure on the node counted passes, not people

The funnel labelled `COUNT(*)` over node runs as "this many reached this step".
But a loop writes one row per pass for each body node, and a wait-until is
written again when it resumes. With ten loop passes the body node reported ten
times as much — and because the bars are measured against the busiest node,
every other step shrank to a fraction. The view drew a collapse exactly where
there was none, that is, at the one question it exists for.

What is counted now is distinct runs (`COUNT(DISTINCT automation_run_id)`), and
per node rather than per node and result: a run that fumbles a step and manages
it on the second attempt arrived there once.

Measured against real data: a step with four rows for one person.

### Fixed — the export

- **Cells are safe to open.** `subject` comes from the trigger context, which a
  stranger fills through a form or a webhook; the enrolment filter trims and
  lowercases it, nothing more. Excel executes a cell with a leading `=` on
  opening, as the person with `view automation runs`.
- **Backslashes stay put.** PHP's default escaping is not part of RFC 4180 and
  breaks up every value containing a backslash — that is, every error message
  with a class name in it. Plus a BOM, so that "Willkommensgruß" does not arrive
  as a jumble of letters.
- **The file follows the table's sort order.** `Listing` remembers the reader's
  choice; anyone who had sorted ascending once got every file from then on in
  the reverse order of the table it claims to be.
- A step whose node was deleted is named as such in the file. On screen there
  was a marker for it, in the file nothing.

### Fixed — "in the flow" concealed the ones that matter

The period was applied to that list as well. Somebody enrolled 40 days ago and
parked in a 60-day wait fell out under the default "last 30 days" — and out of
the count beside it along with them, so that nothing on the screen even hinted
that somebody was missing. The question is "who is in it now", and that is not a
question about a period.

### Fixed — small things

- The status column showed `success` and `failed` raw, while the filter menu
  beside it offered "Erfolg" and "Fehlgeschlagen". The same fact in two
  languages on one screen.
- The "Completed" tile is now called "Ran to the end" and has a translation of
  its own. The key `Completed` belongs to LeadHub (for a finished task), and
  CP-wide merged dictionaries would have overwritten their word.
- The sentence about the runs without a person said ":n more" above an empty
  table.
- A node run without `created_at` would have made the export abort silently at
  that point.

## 2.5.0 — 2026-08-15

### Added — an automation's mails now appear on the contact

The contact page in LeadHub answers "what has this person received from us".
Campaigns register there from marketing's side themselves; the mails an
automation sends — often the very first ones somebody receives at all — were
the one missing part of that answer.

What the entry **cannot** say, it says itself: an automation mail goes through
the mailer, not through marketing's measured sending path. No pixel, no
rewritten links, so no open and no click. One entry, "sent", with that note
attached. A timeline that stays silent about it reads as "never opened", and
that is a different and untrue thing.

- **No class name of the sibling addon appears here.** Everything runs through
  `Integrations\LeadHub\LeadHubAdapter`, which fetches LeadHub from the
  container and answers "not installed" without an error. That is what keeps
  the integration optional.
- **Only for contacts that already exist**, and never fatal: the path hangs at
  the end of an already successful send.
- **Test runs write nothing.** `automations.test_mode.send_real_emails` is a
  shipped option; with it on, the success path supplies a real address again.
- `marketing.send_email` stays out of it and keeps registering itself,
  otherwise every such mail would appear twice on the contact.

Can be switched off through `automations.timeline.enabled`.

### Fixed

- `AutomationRun::automation()` is now documented as a relation that can be
  empty as well: a run outlives the automation it came from. That drops an old
  finding from the phpstan baseline.

## 2.4.1 — 2026-08-14

Everything from the critic round on 2.4.0. The bar was in place, and the
catalogue beside it kept pointing at the gap.

### Added — the second route to the same defect is named

The bar recognised marketing mail only by the marketing trigger (`subscriber.*`
in the context). There is a second route to the identical mail, and the
catalogue leads there: `form_submitted` → `marketing.subscribe` → a mail to the
address that was just subscribed. That is the "Form Submission to Newsletter"
template plus the obvious next node.

This case is **warned about, not refused**, and that is the point: the same
graph is also the delivery of a requested file to somebody who was subscribed
beforehand. Both readings are real, nothing in the run separates them, and a
refusal would be a guess — and what would be guessed is whether to break
somebody's running flow. The warning names `marketing.send_email`. The template
itself now says so in its description, because that is where the decision about
what gets built next is made (in `statamic-marketing` 2.7.2).

### Fixed — three holes in the bar

- **Display name and recipient list.** `Lea <lea@example.test>` and
  `team@example.com, lea@example.test` slipped past the bar, because the
  comparison was raw. A display name is not a different recipient. Plus
  addresses and dots deliberately stay unnormalised — those would be different
  mailboxes.
- **The kill switch stayed silent.** Anyone switching
  `refuse_marketing_recipients` off now gets a warning in the log for every send
  that is let through. A switch that quietly returns you to the original defect
  is the most silent way to get it back.
- **The seam to the marketing addon was unverified.** The bar finds the sibling
  through a class name as a string; a rename over there would have degraded it
  soundlessly to a log line. The pin now sits in `statamic-marketing`'s
  integration suite, where both packages are really installed.

### Docs — the one template that mails a real person now says why it may

`lead_magnet_delivery` is the only shipped catalogue entry that does not write
to your own editorial address but to `{{ form.email }}`. It is allowed to: the
mail is the file that was requested seconds ago, and it subscribes nobody to
anything. But that was written down nowhere — so next to a fresh warning stood a
template that looked like its counter-example. The description, the README and
`docs/templates.md` say so now, together with the sentence that matters: the
next mail after it is marketing and needs a subscription and
`marketing.send_email`.

Plus a note on the fixture `tests/Fixtures/stored-automations/hub-2026-07-29.json`:
the nurture sequence in it is a photograph of the defect 2.4.0 stops, and stays
that way deliberately. A compatibility check against tidied data checks nothing.

## 2.4.0 — 2026-08-14

### Changed — "Send Email" is the transactional node, and now says so

The node described itself as suitable for "a transactional **or marketing**
email". It is not suitable for the second and cannot become so: it asks nobody
about consent, suppression list, opt-out or frequency cap — a password reset has
to go out despite all four — and for the same reason carries neither an
unsubscribe link nor a sender identification. Two real welcome sequences were
built on it, both looked right, both sent unchecked.

The description, the `help` on the recipient field, the README and
`docs/sequences.md` now say what the node is for and what `marketing.send_email`
from `goldnead/statamic-marketing` is for. A transactional mail to somebody who
happens to be a subscriber belongs there as well, classified as
`transactional`: that exempts it from the cap and keeps the gates.

### Added — the node refuses marketing mail instead of only warning about it

Words failed to hold twice. If `statamic-marketing` is installed, `send_email`
refuses **one** send: a mail to exactly the person whose subscription the run is
about (`marketing.subscribed` / `.unsubscribed` put them into the context as
`subscriber.email` on a named list). That is the shape both historical defects
had.

What is compared are **addresses**, not triggers. The unsubscribe alert and the
"campaign sent" message run on the same triggers but write to your own editorial
address — they are unaffected and have to stay that way. Without the marketing
addon there is no node to point to: then it stays a warning in the log and the
mail goes out as before. The check runs before the test-mode short circuit, so
that it becomes visible on "test" and not three days later.

Can be switched off through `automations.send_email.refuse_marketing_recipients`
(default: on) — deliberately site-wide and not as a checkbox on the node,
because a checkbox on the node gets ticked in the same minute the mistake
happens.

## 2.3.0 — 2026-08-14

Everything from Adrian's pass through the hub's Control Panel.

### Added — the settings are editable

The page was a printout of `config/automations.php` with a note to change the file on the server.
It is a form now: the queue, the retention of runs, the test mode and the editorial list are
written in the Control Panel (`manage automation settings`).

**Only deviations are stored**, one row per changed key in `automation_settings`. Resetting a value
to the default deletes the row — the setting then follows the file again, even if a later release
moves the default. A table mirroring every key would have frozen the defaults of the day of
installation.

`Support\Settings` is the single definition: the form, the validation and the override at boot read
the same list. The old page held its labels in JavaScript and was therefore a second description of
the config file, one that could contradict it.

Not editable, and deliberately so: `storage.driver` (it decides where automations live and cannot
be switched while the system is running), everything from `env()` — a key in the database would sit
in the backup instead of in the secret store — and `integrations`, which is not a setting but a
detection.

The table is **not** brand-scoped, unlike every other one in this addon. These are properties of the
installation; a queue name per brand would mean the worker drains one brand's jobs and not the
other's, without anything anywhere saying so.

### Added — opening and editing a mail from the list

In the mails view a mail could be moved, assigned and deleted, but not read. Clicking the name now
opens it in a stack. The form inside it is `ConfigPanel` — the same one the canvas shows in its
right-hand column — so that a mail has one editor and not two that drift apart.

### Fixed — the editor did not run full width, and its header was grey

Three findings, one cause. The page carried `bg-body-bg`, that is, the page background, although it
sits in the content card: hence the grey band behind the header, while every other Control Panel
screen is white there. And it pulled itself out of the card with `lg:-mx-12`, which took the header
along — the title stuck to the window edge instead of to the Control Panel's gutter.

The page now lifts the width limit from the inside (`[data-sa-full-bleed]`, see `cp.css`) and keeps
the card's padding. A canvas is not reading text; the 85rem limit left the graph standing in a
column with empty margins on a wide screen.

### Fixed — the three-dot menus had a scrollbar

`DropdownItem` is `grid-cols-subgrid`, and `DropdownMenu` is the grid that defines those columns. In
three places — the editor's header, the node card, the variable inserter — the items sat in the menu
without that frame. Without the grid every row is a few pixels wider than the menu, and a horizontal
scrollbar appears at the bottom edge.

### Fixed — the run log's stack never opened

`Stack` has a controlled `open` property defaulting to `false`, and `name` is not a property at all.
So the log was mounted and never shown. On top of that, `StackHeader`'s heading is called `title`,
not `heading` — `heading` fell through as a plain HTML attribute and the bar stayed empty.

### Changed — the dashboard looks like the rest of the family

The figures used `Widget`, a dashboard frame with a heading line of its own and a minimum height, in
which every number hung in the top left of a tall empty box. Now `Card` + `Subheading` + `Heading`,
as in `statamic-marketing`. The charts sat directly in a `Panel`: a panel is a section with a
heading, not a surface, so its body took on the page background and read as a grey block next to the
white tiles. They sit on a `Card` now.

### Changed — "Mail rules" only appears in the menu when there are any

The page edits automations that are one trigger and one mail, from the sentence outwards. Where
there are none, it is empty and its only link leads to the canvas — it read as a menu item that does
nothing but redirect. `Sequence\MailRules` answers the question with an `exists()` rather than by
loading every automation the way the page itself does: the navigation asks on every request. The
entry comes back on its own with the first matching automation, and the page stays reachable through
its URL the whole time.


## 2.2.1 — 2026-08-13

### Fixed — the re-entry rule was not read at all by four triggers

`EnrollmentGate` was written for `TriggerDispatcher`, and it was asked there. The four listeners of
this addon — marketing, LeadHub, form submission, entry published — build their context themselves
and called `createRun()` directly. For every automation started by one of them, **nobody** read the
rule on the trigger node.

The defect was silent in the worst way: the field is in the configuration, the Control Panel shows
the choice, an export carries it along, and nothing happened. `marketing.subscribed` is the trigger
a welcome sequence starts with, and the one where `ignore` matters most: somebody who unsubscribes
and subscribes again got the whole sequence a second time, in parallel to the first, both of them
still running. That is exactly what the rule is supposed to prevent.

On top of that, `automation_runs.subject_key` always stayed `null` for those four triggers — the
value the funnel counts distinct people by, and the one the same person's next event is measured
against.

New: `Concerns\AppliesEnrollmentPolicy`, used by all four listeners. For every automation on the
default `always` nothing changes, and that is every one of them until somebody chooses otherwise.
The test for it fails against the previous version (two runs instead of one).

## 2.2.0 — 2026-08-12
### Changed

- **The five sender-identity classes moved to `goldnead/statamic-brand-context` 1.8.0**, which is
  now required at `^1.8`. They were four byte-identical copies with four namespaces — this package,
  marketing, notifications and preference-center each grew their own on 12.08.2026 — and copies
  drift: by the evening the marketing one had stopped refusing a transport without an address, and
  disagreed with this package about whether a per-message from-address beats the brand's. Both are
  settled in favour of the stricter reading, which is the one this package already had.

  Behaviour is unchanged here, down to the log lines and the `help` text on the `send_email` node's
  `from` field. `Goldnead\StatamicAutomations\Contracts\SenderIdentityResolver` and
  `Sending\BrandMailer` stay as this package's own extension points. `Sending\SenderIdentity` and
  `Sending\SaidRecently` are gone from this namespace; use the `Goldnead\BrandContext\Sending\`
  versions.

## 2.1.0 — 2026-08-12

### Fixed — the `send_email` node paired one brand's address with another's relay

The node called `Mail::html()` or `Mail::raw()`. The transport was therefore always
`config('mail.default')`, and the only sender it ever set was the one typed into the node. On a
multi-brand host that splits exactly the pair that matters: a nurture sequence addressed as
`hallo@familystack.de` went out through the relay project that verifies `gldnr.studio`. A provider
that checks sending domains per account (Scaleway TEM, Postmark, SES) then refuses the address or
replaces it with its own verified one — and both happen silently.

**Sender and transport now come together from `brands.settings.mail`.**
`Contracts\SenderIdentityResolver` answers "which mailer, which address, which language for brand
N", and `Sending\BrandMailer` is the one place that asks the question.

| Key | Meaning |
| --- | --- |
| `from_address` | required as soon as `mail` is set at all |
| `from_name` | otherwise the brand's name |
| `mailer` | a mailer from `config/mail.php` |
| `locale` | the language of their mail |

The answer sits on the message, never in the config: Laravel reads `mail.from` when it first
resolves a mailer, burns it into the instance with `alwaysFrom()` and keeps that instance in the
`mail.manager` singleton. A `Config::set` therefore outlives its own `finally`, even with a clean
teardown — that would be the same mistake one level down.

### Changed — the brand wins against the node's `from`

Only where a brand declares an address of its own. A brand that does so has told the host which
address its relay account owns; a node override would hand that assurance to whoever edited the
flow last. Where no brand declares anything — that is, in every single-brand installation — the
node's `from` still decides alone, unchanged.

### Changed — a brand with a broken mail identity sends nothing

A brand that declares `settings.mail` but carries no `from_address`, or that names a mailer
`config/mail.php` does not know, sends **nothing at all**, is logged at error level (throttled per
brand), and the node reports a failure. The alternative would be delivery under the host's address,
that is, under somebody else's name on a multi-brand host.

**The dedupe key is not set in this case.** It is a stamp with a year's shelf life; for a mail that
never went out it would suppress exactly the second attempt that fixing the brand's settings is
supposed to enable.

### Unchanged, with a reason — `FailureAlerter`

The failure mail stays on the default transport. This is the application writing to its own operator
about a broken run, to an address from the config; it speaks for no brand. Picking up the brand from
the context would be worse rather than better — a failing automation of brand A would then look like
brand A writing to the host's administrator.

**A single-brand installation does not change, and that stands there as a test, not as an
intention.** The same goes for a multi-brand installation whose brands carry no `settings.mail`. A
host that keeps sender identities elsewhere rebinds `SenderIdentityResolver` in its own provider,
instead of changing this addon.

## 2.0.0 — 2026-08-09

### Removed — editions and the licence manager

The addon shipped a Free/Pro edition split: `extra.statamic.editions`, a
`LicenseManager` with a local key list and a remote verification endpoint, a
Pro gate on the AI action and on custom node registration, and a License panel
in the Settings screen.

That contradicts how this family is sold. There is one feature set, and
entitlement is enforced by the Statamic Marketplace rather than by code in the
package — the Marketplace has no licence-check API to call, and building one
means shipping a gate a buyer can simply switch off.

Gone with it: `config('automations.license.*')`, the feature flags
`custom_actions_requires_pro` and `ai_action_requires_pro`, the
`GET /cp/automations/api/license/status` route, `Automations::license()`, and
the `STATAMIC_AUTOMATIONS_LICENSE_*` environment variables.

**What changes for a host:** the AI action and custom action/trigger
registration now work unconditionally. Anything that set those config keys or
read that route needs updating — hence a major version when this is released.

## 1.11.0 — 2026-08-05

### Added — mail rules: a one-mail automation as a sentence

An automation that does exactly one thing — when something happens, send a mail — reads as a
sentence: "When a form is submitted, send the thank-you mail to the sender." Opening a canvas with
two boxes for that is the wrong interface. New is the screen
**Tools → Automations → Mail rules**, which shows every automation with exactly one mail node as a
row and makes it editable from that row.

```
GET   /cp/automations/api/automations/{automation}/rule
PATCH /cp/automations/api/automations/{automation}/rule
```

Editable are the recipient, the template, on/off and the sync switch from 1.10. Only what was sent
is written — a status change in one row must not overwrite the template somebody else has just
chosen.

**What the view explicitly cannot do.**

*Create.* It edits existing automations, as the mail list view does. Creating an automation from a
row would mean deciding the trigger, the node type and the handle all at once; that is a cut of its
own. Built on the canvas, it appears here as soon as it is one trigger, one mail and one edge.

*Edit a shape that is not a rule.* `Sequence\RuleShape` decides that and builds on `LinearityRule`
for it, instead of implementing the same graph rules a second time: every reason a mail list is not
editable is also a reason for a rule. A delay between the trigger and the mail, a second mail, a
branch — the row is shown regardless, with the reason attached and a link to the canvas. Showing it
is the point: "which mail goes out when the contact form is submitted" deserves an answer, even if
the flow behind it has grown a delay by now.

*Write a field the mail node does not have.* The recipient is `to`, the template is `template` — but
both are looked up in the node's schema first (`Sequence\RuleFields`). A mail node that pulls its
recipients from a list has no `to`; writing it regardless would leave a config key nothing reads —
a change that looks as though it arrived. The reading and the writing side use the same lookup, so
a row can never show one field and write another.

**The warning on the sync switch now says the right thing.** Not "errors surface in the request"
(they do not, see 1.10), but: the request waits for the whole run.

**`statamic-notifications` gets no sending path of its own**, only a nav entry pointing here. If
both addons could turn an event into a mail, "why did this mail arrive" would have two possible
answers and no way to tell them apart.

## 1.10.0 — 2026-08-05

> Never tagged separately. These changes shipped with 1.11.0: the state of 1.10.0 had no green CI
> run of its own, and in this family only what was fully green gets tagged.

### Added — sending can be switched to synchronous per trigger

Every run went through the queue so far, without exception. For most automations that is right; for
a mail that has to be out before the page has finished loading, it is not. Anyone moving such a mail
out of their own controller into an automation silently turns it into a queue job — the move is then
not behaviour-neutral, so nobody makes it, and the automation layer stays unused for exactly the
mails it would help most.

New: `_dispatch_mode` on the trigger node, default `async`. An unknown value is read as `async` —
the conservative direction is the one that changes nothing.

On the trigger and not on the automation, for two reasons. An automation can carry several triggers,
and only one of them is the one from the request; a nightly sweep of the same automation still
belongs in the queue. And that puts the setting where its neighbour already sits: the re-entry policy
is read from the same node config two lines earlier.

**What the switch does not do: it does not change the error handling.** An error ends up as `failed`
on the run and not with the caller, synchronously as well, because `WorkflowRunner` never throws.
What changes is the timing: synchronously, the run is finished before the caller moves on. The price
for that is time — the request waits for every node, every HTTP call, every mail.

## 1.9.1 — 2026-08-05

### Fixed — the breakpoint-less single-column grid utility is no longer used

Every addon in this family ships its own Tailwind build, and `@statamic/cms/tailwind.css`
routes all of them into the same `addon-utilities` layer. Media queries add no specificity, so
the bare single-column grid rule from whichever addon stylesheet loads **last** won against an
earlier addon's `sm:`/`lg:` variant and pinned that addon's grid to one column at every width.

Invisible when this addon is checked alone. It only appeared once two addons of the family were
installed together, which is the normal case on a real site.

A grid falls back to one column on its own, so the class bought nothing. The overflow guard its
`minmax(0,1fr)` track provided is preserved explicitly, because the implicit column is `auto`.

## 1.9.0 — 2026-08-04

<!--
    Additive, and all four parts ship inert. The re-entry policy defaults to
    the behaviour every automation has today; the mail list is a second way to
    read a graph nobody has to open; the funnel counts runs that were already
    there. A `composer update` changes no run.

    The one thing it does change is the shipped Control Panel bundle, which was
    three releases out of date. See "Fixed" below.
-->

### Added — the enrollment funnel, read out of the runs that were already there

An automation knew how many times it had run. It did not say how many people
were *in* it right now, how many had come out the far end, and how many had
left along the way — which are the three numbers that tell you whether a
sequence works.

`Support\RunStats` answers all three from `automation_runs`, grouped by status,
in one query for the whole listing. No new table: a run *is* an enrollment, and
a second table recording the same facts would be a second place for them to
disagree. Test runs are left out, because an editor pressing "test" is not a
person going through the flow.

What was genuinely missing was an index. `automation_uuid` and `status` existed
as two separate single-column indexes, which answers "this automation's runs"
and "everything that failed" and neither of the questions above. The migration
adds `(automation_uuid, status)`.

### Added — a re-entry policy, so a repeat sign-up stops meaning a second welcome series

Until now, every matching event created another run. For a webhook that is
right. For a five-mail sequence it is the most common way to mail somebody
twice in one morning: they unsubscribed, subscribed again, and now two copies
of the series are ticking.

A trigger can now carry one of four rules — the field is on every trigger,
including third-party ones and the config-driven event triggers, because the
registry appends it rather than each class declaring it:

- **Enroll again every time** — today's behaviour, and still the default. **No
  existing automation changes.** An unrecognised value reads as this one, so a
  typo in an imported file cannot start suppressing enrollments.
- **Ignore** — once per contact, ever. What a welcome series wants.
- **Restart from the beginning** — cancel the open pass and start fresh. The
  scheduled job goes with it; a cancelled run whose wake-up call survives
  resumes days later beside the new pass, which is the exact thing this rule
  exists to prevent.
- **Leave the running pass where it is** — an open pass carries on from its own
  position; nothing new is added.

Runs now carry `subject_key` (normally the lower-cased address) so the three
rules have somebody to compare against, and so the listing can tell enrollments
from people. A trigger that names nobody — a scheduled sweep, a webhook with no
address in it — falls back to the default and says so in the log, because
treating every subjectless run as the same subject would make one nightly sweep
block every later one for ever.

### Added — the mail list: the same automation, read as the mails it sends

A sequence is a list of mails with gaps between them. A graph is the right tool
for building one and the wrong one for reading it back. There is now a second
view of the same object — no second object, no compile step, no synchronised
copy.

**Showing it always works.** Even a branched flow has a knowable set of mails
and knowable gaps; the list marks the ones only some readers get as
conditional, and names the fork they hang off. It is incomplete as a picture of
the flow and correct as what it claims to be.

**Editing is bound to the flow being a straight line**, and the rule is written
out in full on `Sequence\LinearityRule`: one trigger, no node with more than
one edge in or out, every edge on the `default` output, no Branch / Switch /
Loop / Parallel node, everything reachable from the trigger, no cycle. Where it
does not hold, the list stays readable and the canvas is the editing surface —
erring towards "locked when it need not have been", because the other direction
rewrites a graph nobody asked to have rewritten.

**Every gap is measured from the mail before it, never from the start.** That
is what makes reordering lossless: "5 days after the previous mail" travels
with the mail when it moves, where "day 7" would silently misdescribe every row
below the one that moved.

Endpoints: `GET`, `POST`, `POST …/reorder` and `DELETE` under
`api/automations/{automation}/mail-list`. The three writes snapshot a version
first, refuse a non-linear graph with a 422 that carries the rule's own
reasons, and rewrite the chain rather than patching four edges around a moved
node.

A node declares itself a mail with a static `mailStep(): bool` — which is how
`goldnead/statamic-marketing` contributes its send node from its own side, and
why this addon still knows nothing about newsletters. Additional handles can be
named in `automations.sequence.mail_nodes`.

### Added — the mail list has a screen now

The endpoints above had no surface. The builder page carries a **Flow / Mails**
switch: the same automation, the same page, read either as what it does or as
what it sends. A view and not a second screen, because two screens over one
object is how the two start disagreeing.

**Showing works for every automation**, branched or not. A mail only some
readers get carries a `Conditional` badge and the fork it hangs off in words
underneath it. Anything else sitting in the same gap — a tag, a CRM write — is
named on the row, so a reorder is never a silent rewrite of what the flow does.
Each row says how long after the *previous mail* it goes out, and the first row
says how long after the trigger; no row ever says "day 7".

**Where the list may not be edited, it says which of the seven conditions is
broken.** "This automation is not linear" is a sentence an editor cannot act
on: it names no node, no condition and no next step. Instead the notice reads
*Condition 5 of 7: the automation contains no Branch, Switch, Loop or Parallel
step* — with what to do about it, the rule's own sentence naming the node
underneath, and a button back to the canvas. The rule hands out prose, so the
mapping from its sentences back onto the numbered conditions lives in
`resources/js/support/mailList.js` and is tested against the sentences the rule
actually emits.

Reordering is two buttons per row, not a drag handle: a drag is unreachable
from a keyboard and silent to a screen reader, and focus follows the row it
moved. Deleting goes through Statamic's `ConfirmationModal` and says that the
waiting time in front of the mail goes with it while everything else in that
gap is kept.

Three separate locks, each with its own message, because they call for three
different actions: the flow is not a straight line (rework it on the canvas),
the user lacks `edit automations` (ask for it), or the canvas holds unsaved
changes (press Save first — a list edit writes straight to the stored
automation, and the next canvas save would otherwise put the old order back).
After a list edit the page re-reads the stored graph, so the canvas is never
left showing the order the server has just replaced.

The page also gained the enrollment funnel it was already being handed, as
badges above the list.

### Changed

- `Sequence\MailSteps` answers `isMailHandle()` as well as `isMail()`, and the
  builder page hands the screen a `mailTypes` list built from it. A node only
  becomes a mail *row* once it is on the canvas, so an automation that sends
  nothing yet — the one that most needs to add its first mail — could not have
  read the candidates off its own rows. Asking the registry is what keeps the
  UI from hardcoding a handle, which is the one thing `MailSteps` exists to
  prevent.
- `Nodes\Actions\SendEmailAction` declares itself a mail step and summarises
  itself for the list. Its behaviour is unchanged.
- The automations listing carries `in_progress`, `completed` and `exited`
  columns. `runs_count` still counts everything, including test runs, so
  nothing an existing screen shows has moved.
- The models carry `@property` annotations. Static analysis stopped needing
  164 of the 366 baseline entries, and the baseline shrank accordingly.

### Fixed — the shipped Control Panel bundle was three releases out of date

`3e611e9` (01.08.) added the `call_real_ai` option and its description to
`resources/js/pages/Settings/Show.vue` without rebuilding `resources/dist`. It
sits six commits after the last dist commit, in the middle of the hardening run.

So **1.8.0, 1.8.1 and 1.8.2 all shipped a Control Panel in which that option does
not exist**, while `Nodes\Actions\AiGenerateAction` supported it the whole time.
Anyone on those versions could not switch an AI step to the real provider,
because the control was not in the bundle they installed.

The bundle is rebuilt and `npm run build:check` passes again. Nothing else about
the option changed; it is the same code that has been in the source since 1.8.0.

`scripts/check-dist-fresh.sh` names this exact failure in its own header comment,
citing the webhook-manager "vue is not defined" incident. The guard existed. It
was not run, because twelve repositories were being hardened at once.

## 1.8.2 — 2026-08-01

### Fixed — the Test button could never pass for a whole class of automations

A test run starts from an empty context, so `{{ lead.id }}` resolves to nothing. Nine LeadHub
actions validated their lead / contact / opportunity reference *before* the test-mode
short-circuit, which meant they returned `failed` in every test run — on correctly configured
automations. A real case: `waitlist-follow-up-task` reported

```
leadhub.add_tag   failed :: Both lead reference and tag are required.
```

with the node configured as `{"lead_id":"{{ lead.id }}","tag":"waitlist"}`. Nothing was wrong
with the automation. The Test button simply could not be used on any chain that acts on a lead,
which is most of them.

**Where the line now runs.** Not "skip validation in test mode" — that would let a genuinely
broken node pass:

- **Static configuration is still validated before the short-circuit.** A missing tag, note
  body, task title, target status, target stage or pipeline fails a test run, because that node
  is broken and would be broken in production.
- **Data references are validated after it.** The fields a schema declares as `data_reference`
  can only be filled by the run itself, so a test run previews them as empty and carries on. On
  the live path they are still required, and now fail through
  `ActionResult::missingDataReference()`, which records the field handle in the node output.

Reordered: `leadhub.add_tag`, `leadhub.remove_tag`, `leadhub.add_note`, `leadhub.change_status`,
`leadhub.change_score`, `leadhub.create_follow_up`, `leadhub.complete_follow_up`,
`leadhub.move_stage`, `leadhub.create_or_update_opportunity`. Unaffected, and checked:
`leadhub.create_task` (its lead reference is optional, and its required `title` is static
configuration that must keep failing), `leadhub.create_or_update_lead` (`email` is configured,
not a reference), the three Marketing actions, `webhook_manager.send`, and all seventeen native
actions — none of them validated a reference ahead of their test-mode branch.

`test_mode.persist_leadhub_changes` is unchanged: with it on, a test run behaves like a live run
for LeadHub, reference check included.

### Fixed — an error message that accused the wrong field

`Both lead reference and tag are required.` was returned when the tag was set and only the
reference was missing, which sends the next person to check the wrong half of the node. Messages
are now split per field and name the reference and the token that should have filled it:
`No Lead to act on: the "Lead" field is empty and {{ lead.id }} did not resolve in this run.`

### Fixed — `leadhub.move_stage` mislabelled its opportunity field

`opportunity_id` was declared `type: text` while the action read `{{ opportunity.id }}` from the
run context — a data reference in everything but the declaration, which is why it was missed by
eye twice. It is now declared `type: data_reference`. The CP renders it identically (both types
map to a text input with the token inserter); stored automations keep loading, since the handle
is unchanged.

### Added — a structural test, so the next action cannot repeat this

`tests/Feature/TestModeDataReferenceTest.php` walks every `AutomationAction` class in `src/` off
the filesystem — not off the registry, since the integration actions only register when the
sibling addon is installed — builds a config that fills everything except the data references,
and runs each action in test mode against an empty context. Nothing may fail on an unresolved
reference. It also asserts the converse: outside a test mode, a required reference still refuses
to run, and names itself.

Three actions are allowed to fail the sweep, each with its reason recorded in the test
(`ai_generate` needs a Pro licence, `call_automation` and `marketing.send_campaign` point at
resources that do not exist in the test app). That list is asserted exactly, so a new action
that repeats the defect makes the suite red rather than sliding in.

### Documentation

`docs/getting-started.md` gained a "What a test run does (and does not) check" section: the
reference rule above, the full `test_mode.*` table including `persist_leadhub_changes`, and how
to hand a test run a real context instead of loosening a flag. The `call_real_ai` flag now has a
proper label on the Settings screen instead of the generic fallback.

## 1.8.1 — 2026-08-01

### Fixed — the "Webhook Failure Alert" template could never fire

The template shipped with the trigger `webhook_manager.outbound_failed`, and nothing registered
that handle. Installing it produced an automation that looked complete in the builder, stayed
enabled, and never ran once, no matter how often a destination failed. If you installed it, it
starts working after this update; nothing to reconfigure.

The trigger now exists (`Outbound Webhook Failed`, under the Webhook Manager group) and is
bridged to Webhook Manager's `DeliveryFailedTerminally` event — the one it has fired all along
when a delivery exhausts its retries. Registration is guarded on Webhook Manager being
installed, exactly like the inbound `webhook_received` bridge, and the event class is
overridable via `automations.integrations.webhook_manager.outbound_failed_event`.

The template's `min_attempts` field is now a real field on that trigger: the automation only
runs once the delivery has been tried at least that many times. It previously sat in the
template as config no code read.

Context exposed to the flow: `webhook.destination`, `webhook.destination_name`, `webhook.url`,
`webhook.attempts`, `webhook.status`, `webhook.error`, `webhook.delivery_id`.

### Fixed — failure alerts for a deleted automation silenced each other

`FailureAlerter` throttled per `automation_id`. A run whose automation has been deleted has
`automation_id = null` (the foreign key is `ON DELETE SET NULL`), so every such run in the
installation shared the single cache key `automations:alert:` and the first failure suppressed
all the others for the whole throttle window. The throttle now falls back to the run's
`automation_uuid`, which survives the delete, and the alert text names the automation instead
of printing a bare `#`.

### Added — a test that holds every template against the registries

`tests/Feature/TemplateNodeCoverageTest.php` walks all eleven built-in templates and checks
every node handle against the node registry, every config key against that node's schema, every
edge against the template's own node keys, and every `requires` entry against the integration it
names. A template is a pile of strings pointing at registrations elsewhere; neither `php -l` nor
PHPStan can see when one of them points at nothing. This is the third defect of that shape in
the addon family, and the first one caught by a test rather than by reading.

### Fixed — the test suite ran without foreign keys and hid a real one

SQLite ignores foreign keys unless asked to enforce them, so the suite accepted rows MySQL
rejects outright and never performed the `ON DELETE SET NULL` the schema promises. One test
built an orphaned run by inventing `automation_id = 999`, a data shape production cannot reach.
`foreign_key_constraints` is now on for the SQLite bed, and that test takes the production path:
create the automation, run it, delete it, let the database null the column.

### Changed

- The automations empty state no longer promises "eight built-in patterns" while eleven ship.
  The count comes from the registry, so it cannot go stale when a template is added.

## 1.8.0 — 2026-08-01

### Fixed — "Start from a template" led nowhere

The button on the automations index built its target by rewriting the *create* URL, and that
URL carries a doubled `automations/automations` segment. The Templates screen was registered
and working the whole time at `/cp/automations/templates`; only the link was wrong. The target
now comes from the controller instead of from string surgery.

Worth knowing for anyone debugging a CP link: Statamic registers two catch-all routes, one for
the CP (`cp/{segments}` → `statamic.cp.404`) and one for the front end (`{segments?}` →
`statamic.site`). So *every* `/cp/…` string matches a route on some verb, and "does it match a
route" tells you nothing. Only the route name distinguishes a live target from a dead one.
`tests/Feature/CpLinkTargetsTest.php` now walks the Inertia props of every page and checks each
CP URL against the registered route names, so the next dead link fails in CI.

### Fixed — every host received 415 KB of dist nobody loaded

`resources/dist/{cp.js,cp.css,.vite/manifest.json}` predated the move to `build/` and no
manifest referenced them, but they shipped in the tarball all the same. Removed, and
`check-dist-fresh.sh` now fails on any tracked file under `resources/dist` outside `build/`.

### Fixed — listeners and CP routes registered twice under test

Moving to `Statamic\Testing\AddonTestCase` surfaced that the manual `bootAddon()` call had
become a duplicate: saving one entry ran every listener twice. Both the manual call and the
hand-mounted routes are gone.

### Changed

- 25 hardcoded colours moved onto theme tokens, so the canvas and the node palette follow
  Statamic's dark mode instead of approximating it.
- The node palette is reachable by keyboard.
- `laravel/framework` narrowed to `^12.0|^13.0`. The 11.x line is withdrawn behind security
  advisories and cannot be installed, so declaring support for it was untrue rather than
  generous. `orchestra/testbench` follows to `^10.0|^11.0`, and `pestphp/pest` gains `^4.0`,
  which is what actually installs on Laravel 13.
- The README no longer describes a drag-to-canvas flow that was removed, no longer claims the
  screenshots don't exist while shipping six, and links into the docs site rather than into a
  `docs/` folder that `.gitattributes` strips from the tarball.
- The 141 JavaScript tests now run in CI. They existed and were never executed there.
- Larastan and Pint are wired in as gates; the `repositories` block, which Composer ignores in
  a dependency anyway, is gone now that the siblings resolve from Packagist.

## 1.7.1 — 2026-07-30

### Fixed — `automations:sync` could import over a database it could not see

The command took no brand. A console run has no session, so under multi-brand the global scope failed closed and every query came back empty — and here that is worse than a no-op, because the command asks the database a question before deciding what to do.

`detectDirection()` checks whether the database holds any automations. It saw none, concluded the files must be the source of truth, and a bare `automations:sync` would import them over automations it simply could not read. `--from=db` was the harmless direction: it exported nothing and said so.

**The fix does not iterate brands**, because `resources/automations/` cannot hold more than one. It is a single flat folder of `{handle}.json`, and handles are unique *per brand* — two brands may each own a `welcome-flow`, so exporting both would have the second overwrite the first, and importing cannot know which brand a folder belongs to.

So the command now refuses to guess:

- More than one brand and no `--brand` is **rejected**, naming the brands and the reason. Run it once per brand with `automations.file_storage.path` pointed at a directory of its own.
- An unknown `--brand` is rejected rather than silently falling back.
- Single-brand installs are unaffected — no option, no prompt, same behaviour.

`tests/Feature/SyncBrandGuardTest.php` covers it; four of its five cases fail without the fix.

> Same class of defect as the one `RunsForEachBrand` was written for, and the third addon to hit it. The trait was not the answer here: iterating brands is exactly what must **not** happen when the target is one shared directory.

## 1.7.0 — 2026-07-29

A node's output handles are now declared once, by the node, and read from that one declaration by the canvas, the validator and the node's own `outputs()`. The reason this is a **minor** and not a patch is that it adds public surface — a field in the node-library payload, an extension point third-party nodes are meant to implement, and a documented wire contract with a version on it — and changes one visible behaviour (Duplicate on a loop). The reason it is not a major is that nothing a consumer already depends on was removed or renamed: no stored data, no route, no API response shape, no permission, no output handle string.

### Added — the registry hands out a node's outputs, so a third-party node can have more than one

Until now, which handles a node has was written twice. `SwitchNode`, `ParallelNode` and `LoopNode` each declared `outputs()` in PHP, and `outputsFor()` in `resources/js/composables/useAutoLayout.js` mirrored all three by hand, down to how they read `config.cases` and `config.branches`. The mirror was accurate — the tests pinned it — and it was also the whole of the canvas's knowledge. `NodeRegistry::describe()` did not expose outputs at all, so a node registered by anybody else got a single `default` handle whatever its class declared. Since a handle is the only thing an edge can leave from, its second and third outputs did not exist as far as the builder was concerned.

1.5.5 fixed the sharpest edge of that — a type ending in `.branch` got true/false on both sides, because `FlowValidator` had required true/false off that suffix since the first release and the canvas offering one `default` made such a node *less* usable than any other custom node. It left the double declaration standing, and named the reason: outputs can depend on config, so exposing them means handing a config-dependent thing to a frontend and versioning it.

That is what this release does. `describe()` now carries an `outputs` key for every node — not a list of handles, which would be wrong the moment a switch gains a case, but a small declarative spec that both sides resolve against the node's live config. `src/Support/NodeOutputs.php` holds the grammar and the PHP resolver; `resources/js/composables/useNodeOutputs.js` is the same resolver in the browser. A node writes `outputSpec()` (with the `DeclaresOutputs` trait deriving `outputs()` from it), or, if its handles are fixed, just `outputs()` — the registry serialises that for the canvas, so the common third-party case needs no knowledge of the grammar at all.

`outputsFor()` on the canvas no longer contains a single test of a node's type. It looks the type up in the library payload the page was rendered with and resolves what it finds; a type with no declaration gets one `default` continuation, which is what every custom node got before. The `.branch` suffix rule moved out of the canvas and into the registry, where it is now a fallback for a type that declares nothing rather than a cap on what such a type may declare — an `acme.branch` may now declare three outputs and get three.

**What the promise is worth, checked end to end.** `tests/Feature/ThirdPartyNodeOutputsTest.php` registers `acme.review`, a node this package knows nothing about that declares `approved` / `rejected` / `escalated`: all three reach the canvas in the library payload, the validator holds the graph to exactly those three and names a fourth handle as unknown, and a run routes down whichever one the node returns. `tests/js/node-card-outputs.test.js` mounts the real canvas — Vue Flow, the node cards, Vue Flow's own `Handle` components — and finds three connectable source handles on it, against one before this release.

The engine needed no change and got none: `WorkflowRunner` has always routed on the handle a node returns and never on the node's type. That half of the promise already held; it was the only half that did.

### Added — the payload is versioned, and a mismatch degrades instead of guessing

The spec carries `version` (`NodeOutputs::VERSION`, 1) and the canvas carries the version it understands (`OUTPUT_SPEC_VERSION`). Both are packaged together, but the built assets are published into the host's `public/vendor/`, so a stale canvas meeting a newer server is a real shape and not a theoretical one — it is the shape `npm run build:check` and the publish step exist to prevent.

A resolver meeting a spec numbered above its own does not guess at fields it does not know: it resolves the node to a single `default` output, which is exactly what a canvas that had never heard of output specs did with the same node, and logs once per type saying the assets are behind. The other direction needs no rule — a spec from an older contract uses only fields a newer resolver already understands. Both directions are asserted, in `tests/js/node-outputs.test.mjs` and in `NodeOutputSpecContractTest`.

### Changed — Duplicate on a loop puts the copy after the loop, not inside it

A node may now name its `primary` output: the one that means "and then". Duplicate and insert-on-edge attach there instead of taking the first declared output.

For every node except one this changes nothing, because first *is* the continuation. `LoopNode` declares `done` as its primary, and its outputs are `loop` then `done` — so duplicating a loop used to hang the copy off `loop`, inside the body it was meant to follow. Valid and deterministic, and not what the user meant; 1.5.5 wrote it down as unresolvable because there was no metadata marking a node's main continuation. There is now, and it falls out of the same declaration rather than being a second mechanism.

A branch deliberately declares no primary: neither side of a condition is the continuation, so Duplicate still attaches to `true`, as it has since 1.5.5. Same for a switch (which case is "the" case is the user's business) and an inline parallel (a fan-out has no single continuation).

### Changed — the validator can now check any node's outputs, and says so quietly

`FlowValidator` checked output handles on branch nodes only, because a branch was the only node whose handles it could know. It now asks the registry for any node's declared outputs, resolved against that node's own config.

The level is deliberate. A branch stays an **error** with the same `branch_invalid_output` code and the same message it has had since the first release. Every other mismatch is a new **warning**, `edge_unknown_output`, naming the node, the handle and what the node does declare. Warnings do not block enabling or running (only `level === 'error'` does, in `AutomationsController` and `WorkflowRunner`), and that is the point: a switch's outputs move when its cases are edited, so edges stored against a removed case are ordinary in existing data. Raising those to errors would refuse to enable automations that were enabled yesterday, on a graph nobody touched. The `error` handle — taken by the runner when a node fails under `_on_error: continue` — is never reported, because every node has it whether or not its spec mentions it.

### Compatibility

Stored graphs were the real risk here: `automation_edges.from_output` holds handle strings, `WorkflowRunner::nextNode()` matches them exactly, and nothing reconciles them with anything. A rename or a reorder would not raise an error — it would produce an automation that quietly stops at the node whose handle changed.

So the handles are pinned twice. `NodeOutputSpecContractTest` asserts the pre-1.7.0 `outputs()` results survive verbatim, including order and the switch's dedupe of a case that already targets `default`. And `tests/Fixtures/stored-automations/hub-2026-07-29.json` is not test data: it is the five automations in the running QA hub, exported from its database — a five-step marketing nurture on `default` edges, two delay flows, and the branch graph 1.5.5 was built against, wired on `true` with `false` left open. `StoredAutomationsSurviveOutputSpecsTest` restores each of them, resolves all 18 stored `from_output` values against the new declarations, and requires the validator to report nothing about any of them.

No migration: nothing about the database changed.

Two internals of the JS layer went away with the mirror they served. `isBranchType()` was exported from `useAutoLayout.js` (added in 1.5.5, no callers), and `computeLayout()`'s `options` argument carried `branchTypes` / `terminalTypes`, which the layout no longer needs because it no longer knows a node type by name. `LoopNode::outputs()` gained the optional `array $config = []` its siblings already had.

### Notes

- `tests/Fixtures/stored-automations/` and `tests/js/fixtures/node-output-specs.json` are both fixtures written from something real: the first from the hub's database, the second from the live PHP registry by `NodeOutputSpecContractTest` (regenerate with `UPDATE_NODE_OUTPUT_FIXTURE=1`). The JS suite reads the second, so a change to a built-in node's outputs that is not carried across cannot pass both suites.
- Mounting the canvas under Vitest needs a `getBBox` stub alongside the `ResizeObserver` one 1.6.1 documented, for the same reason and with the same symptom: the throw lands in a post-render hook, so the mount that fails is silent and the *next* one in the file returns an empty wrapper.
- **Known and unchanged:** `SubAutomationAndAlertsTest > failure alerter logs and throttles` fails under MySQL on a foreign-key violation (`automation_id=999`, which SQLite does not enforce). Reproducible at 1.5.3.
- Suite: **392 passed (1585 assertions)** on SQLite, baseline 378. Vitest **45 passed**, baseline 37. `node:test` **91 passed**, baseline 81. Every capability was verified by stashing the source and watching its test fail against 1.6.2: without the PHP half, 9 of the 14 new PHP cases fail (the five that pass are the ones that were already true — the runner's routing, the validator's silence about output handles it could not see, and the resolver's own version guard, which is in a new file the stash left in place); without the JS half, the third-party node renders one handle instead of three, its "+" adders and its layout columns collapse to one, and the loop's copy lands back in the body; without the version guard, a spec from a newer contract is resolved as if the canvas understood it.

## 1.6.2 — 2026-07-28

### Fixed — an interrupted brand-scoping migration could not be repaired by running it again

`2026_07_24_100002_add_brand_id_to_automations_tables` adds `brand_id` to seven tables and then has two places left where it can stop: the `RuntimeException` it raises when `automations` still holds rows with no brand to put them on, and the rework of the handle unique, where the drop and the create are two separate statements and the second can fail on its own.

Neither MySQL nor SQLite rolls DDL back, and a migration that throws is not written to the `migrations` table. So an aborted run leaves a database that is partly converted and a bookkeeping table that says the migration never happened — and, if it stopped inside step 4, a table whose global `handle` unique has been dropped and not replaced, which means the one identifier this addon promises to keep unique is unconstrained from that moment until somebody notices.

The only move available to whoever hits that is `php artisan migrate` again, and unguarded it did not get as far as the problem. It died at the very first statement on `duplicate column name: brand_id` — an error about step 1 that describes nothing that is actually wrong and points whoever reads it at the wrong end of the file. That is the fingerprint `statamic-marketing` documented for its own copy of this migration in 1.6.4.

Correcting the order of the statements would have fixed the next install and left every install that already broke exactly as broken as it is. So the migration is re-runnable rather than merely correctly ordered: the column addition asks each table whether it already has the column, and the unique rework reads the indexes actually on `automations` and does only the part still outstanding — checking the columns and the uniqueness, not just the name, because an index can exist under the right name over the wrong columns and be a promise the database is not keeping. Run on a clean pre-1.5.0 install, on a half-converted one, or twice in a row, `up()` ends with the same schema and raises nothing.

`2026_07_28_000003_require_brand_id_on_automations_table` was reviewed and needed nothing: it was already guarded on `hasTable`, `hasColumn` and a nullability probe.

### Added — the migrations are finally tested against a database with data in it

This is the finding underneath the fix. A sweep across all eight addons in this family, prompted by `statamic-marketing` 1.6.4, looked for a check that runs a migration against tables that already hold rows. It found none, anywhere. Every migration in this addon had only ever met empty tables, because every bed it had was a fresh install — which is the one shape a migration can never be wrong about.

Two properties of `tests/Migrations/` matter more than its individual cases.

It names no migration file. It walks `database/migrations/` and seeds a fresh generation of automations, nodes, edges, runs, node runs, scheduled jobs and audit rows into every table that already exists *before each* migration, so a migration added three years from now is covered the day it is committed without anybody remembering to come back here. A test that lists the two files that were once broken only ever tests the past.

And every assertion about the handle guarantee is behavioural. "The migration ran" and "the constraint is there" are not the same statement, and mistaking one for the other is the entire class of defect — so nothing there checks an exit code or an index name. It writes the row the constraint is supposed to refuse and requires the database to refuse it, together with the counterpart that catches a unique rebuilt over `handle` alone: the same handle in a different brand must still be accepted.

`tests/Fixtures/released-migrations/` holds the migration sets as published in 1.2.0, 1.5.0 and 1.6.1, and the suite installs each of them, puts data in and upgrades forward. `tests/Feature/BrandIdMigrationIsRerunnableTest.php` covers the repair directly, from a populated install stopped halfway through. Reverted against the published migration both of its cases fail, each with the `duplicate column name: brand_id` the fix exists to stop producing.

### Changed — the MySQL key-length probe can read the schema it is measuring

`tests/Unit/IndexKeyLengthTest.php` compiles the migrations through Laravel's MySQL grammar in pretend mode to measure index bytes without a server. Under `pretend()` a `select` returns nothing, so a migration that asks `Schema::hasColumn()` or `Schema::getIndexes()` before deciding what to build is told the table is empty of everything — a state no install is ever in, and now that `2026_07_24_100002` branches on exactly those answers, one that would have had the probe measuring a schema nobody holds.

It now runs two connections interleaved: the probe compiles the DDL through MySQL's grammar, and a real SQLite database one file behind answers every question the migrations ask about the current schema. Same measurements, on the schema that actually results. The same change `statamic-marketing` made in its 1.6.4 for the same reason.

### Notes

- Suite: **378 passed (1379 assertions)** on SQLite, baseline 372. Vitest unchanged at 37, `test:js` unchanged at 81.

## 1.6.1 — 2026-07-28

### Fixed — a refusal now says what the server said, and stays on screen

The automations half of the cross-addon sweep marketing 1.5.3 started: for every mask in this control panel, does a rejected request reach the user? The shape of the problem here is different from its siblings, because this addon does not go through Inertia. Every call is axios, so there is no error bag handed to a page — whatever a `catch` block does not dig out of the response is gone. The defect was therefore never a missing handler. Every submitting function already had one. Four of them threw the server's answer away and replaced it with a guess.

**Four places that answered a rejection with a message of their own invention.** `Automations/Index.vue`'s `duplicate()` and `destroy()` both read `catch (e) { toast(__('Duplicate failed.')) }` — the error was bound and then never touched. The most likely rejection at either site is an authorization failure, and this addon's `Controller::authorizeAction()` throws with the permission it wanted by name (`Permission 'delete automations' is required.`). That sentence was constructed, sent, received, and discarded, every time. `Automations/Edit.vue`'s `validate()` and `exportJson()` did the same with a bare `catch {}`, which does not even bind.

**A branch that could not run, and the reasons it dropped.** `toggleEnabled()`, on both the editor and the listing, checked `data.ok === false` to report a blocked enable. The API returns that shape with HTTP **422**, and axios rejects a 422 — so the check sat on the success path where it could never be reached, and control went to the `catch`, which read `.message` only. The `issues[]` array that comes with it, carrying the per-node reasons the automation cannot be enabled, was dropped on every refused enable since the endpoint existed. Both pages now read it off the rejection: the editor feeds it into the issues panel it already has, the listing into its own.

**One toast line was the whole of a rejected save.** `save()` did read `errors` — it was the only site that did — but reduced the map to its first entry and showed it in a toast. `StoreAutomationRequest` validates 16 keys; a save that fails on three of them said one thing and vanished after two seconds. The bag is now kept: `name` is rendered at the header input it belongs to (the invalid ring was there before, but it was only ever set by the client-side pre-check, so it said *that* something was wrong and never *what*), and everything else — `description`, `handle`, and the `nodes.*` / `edges.*` keys the canvas generates, which name array indices no control corresponds to — goes into a collected block above the editor.

**A rejected autosave left no trace at all.** `save({ silent: true })` suppressed the toast by design and rethrew; `useAutosave` caught it into `lastError`, which no template binds. An autosave failing every two seconds was invisible. It now fills the same error state as an explicit save, so the reason is on screen even though nothing is shouted.

**And an uncaught rejection on every failed save.** `save()` rethrew unconditionally, including out of the header button's click handler, where nothing awaits it — one unhandled promise rejection in the browser console per failed save, carrying nothing the user had not just been told. The rethrow is now confined to the autosave path, which is the only caller that needs it.

`resources/js/support/serverErrors.js` is the one new file: `errorBag`, `errorMessages` and `firstMessage`, so reading a rejection is one import rather than a hand-rolled `e?.response?.data?.…` chain at each site — which is how four of them came to have none. `firstMessage` prefers a real validation message over Laravel's generic `"The given data was invalid."`, which is what the remaining toasts in `Import.vue`, `Runs/Show.vue` and `Templates/Index.vue` were showing whenever a 422 carried an `errors` map.

**The two test layers.** `tests/Feature/CpValidationVisibilityTest.php` reads the sources: every submitting function must have a catch, and no catch may report a failure without reading the response — the check that fails on `catch (e) { … }` where `e` is never mentioned again, which is the exact defect this release removes. `tests/js/cp-validation-visibility.test.js` mounts the two pages and hands them real rejections, in the shapes Laravel sends, and requires the server's sentence to appear in the DOM. All 7 of its tests fail against 1.6.0.

Mounting the editor there needed a `ResizeObserver` stub, and the reason is worth writing down: without it Vue Flow's mount throws into an unhandled rejection, and the *next* mount in the file comes back without a component instance — a missing browser API presenting as a page that will not render. It cost more time than the defects did.

## 1.6.0 — 2026-07-28

### Changed — this addon binds `{automationFlow}`, not `{automation}`

1.5.6 added a guard that compared this addon's route parameter names against a hand-written list of what the siblings bind. The list was a snapshot, and it went stale in the same week it was written: `goldnead/statamic-webhook-manager` 1.7.0 renamed all four of the names it claimed, so four of the fourteen entries were describing a world that had moved on, and the file was silently asserting something false. It was also the wrong shape. What replaces it is the rule webhook-manager arrived at, applied here:

> **A `Route::bind()` is registered on the router, not on the package that calls it. Bind only names that unambiguously belong to your addon — specific enough that no sibling would reach for one by accident. Names you do *not* bind may stay as generic as they like: nothing resolves them, so nothing can be taken from anyone.**

`{automation}` was the last generic name any addon in this family still claimed application-wide. It is renamed to `{automationFlow}` — the addon's own prefix plus a capital, which is the shape the guard test now checks for rather than a list of approved words.

**No URL changes.** `/cp/automations/17/edit` is the same string before and after; a route parameter name is the placeholder, never the path. What changed with it, across 8 files: the one `Route::bind()` registration, the 15 route definitions in `routes/cp.php`, the `$this->route(…)` lookup in `UpdateAutomationRequest`, the bound argument in 15 controller methods across `AutomationsController`, `VersionsController`, `ExportImportController` and `AutomationsPageController` — 61 variable occurrences in all — and one `cp_route(…, ['automation' => …])` in `BrandHandleUniquenessTest`, which would have generated the id as a query string instead of a path segment and produced a 405 rather than the 200 it asserts.

The rename was done method by method rather than by search-and-replace, because three of the `$automation` variables in those same files are *not* route-bound and had to stay: `store()` builds its own, `syncGraph()` takes one as an argument, and `automationPayload()` renders one. The Inertia payload key `'automation' => …` that every Vue page reads is likewise untouched — it is not a route parameter, and renaming it would have broken the builder for no reason.

`$this->route('automation')` in `UpdateAutomationRequest::rules()` is why this was worth doing carefully rather than quickly. It is a null-safe read feeding the ignore-id of a `unique` rule. Miss it and it silently reads null, the ignore falls away, and saving an automation without changing its handle starts failing validation against itself — no error at the point of the mistake, one at the far end.

**Why the guard test changed shape.** It now reads the `Route::bind()` calls out of this package's own `src/` — comments stripped, string literals only, and a call whose name is not a literal fails the test rather than escaping it — and requires every name found to match `automation` + a capital. That is a property of this package, so this package's own suite can enforce it without knowing anything about its neighbours, and a second binding cannot arrive by default.

The behavioural half is `it does not swallow a sibling addon's generic route parameter`. `tests/TestCase.php` now mounts stand-in routes for a sibling package — `{automation}`, `{rule}`, `{template}`, `{webhook}`, `{endpoint}`, `{handle}`, `{id}`, `{slug}`, `{record}`, each doing nothing but echoing its own value — and the test asserts every one of them answers with what it was given. Before the rename, `{automation}` answered 404: the LeadHub defect, reproduced from the losing side inside this package's own suite for the first time. They are registered in the bed rather than in the test body deliberately; a route added from inside a test is shadowed by Statamic's `{segments?}` frontend catch-all and answers 404 whatever the bindings do, which would have made the check pass for the wrong reason.

**What deliberately did not change: `{handle}`, `{run}`, `{source}`, `{nodeRun}` and `{timestamp}`.** They are generic and they are staying. Renaming them would move text without removing any exposure, because they are not bound — nothing resolves them, so nothing can collide. `{run}` and `{nodeRun}` resolve through Laravel's *implicit* binding, which matches a route parameter to a typed controller argument and is therefore scoped to that one route. Only `Route::bind()` is application-wide, which is why only that is the subject of the rule.

**Still true, and still not fixable from here:** a collision exists only once two packages are installed together, and a package cannot see its siblings from inside its own suite. The rule turns that from something each addon must know into something each addon can check alone.

### Fixed — this addon was retranslating the German Control Panel for the whole application

The same shape as the section above, one layer over: `loadJsonTranslationsFrom()` appends a directory to a single list on the translator's file loader, and at lookup time the loader merges every `<locale>.json` in that list into one flat array with `array_merge`. The last package to register a key wins, application-wide, for every caller of `__('…')` including statamic/cms itself. There is no namespace, no prefix and no warning — and an addon's own suite loads its own JSON and nobody else's, so of course it never sees a conflict.

`resources/lang/de.json` shipped four bare Control Panel words that statamic/cms also defines. Two of them disagreed with the core:

| key | statamic/cms | this addon, until now |
|---|---|---|
| `Templates` | Templates | Vorlagen |
| `User` | Benutzer:in | Benutzer |

Neither stayed inside the automations screens. `User` is the plainer of the two: Statamic's German uses a gender-inclusive form throughout, and four convenience strings here undid it on **every German CP page in the install** — entries, assets, users, forms, none of which have anything to do with automations. That is not a matter of taste; it is an addon reversing a decision of the core for the whole application.

The fix follows what `goldnead/statamic-leadhub` did in its 1.9.0: where the addon genuinely means something else, the **source string** is made unambiguous rather than the core's translation overridden. `__('Templates')` — an automation template, not an Antlers view — becomes `__('Automation templates')`, in the CP nav item and in the templates page title, with `"Automation templates": "Automatisierungsvorlagen"` in `de.json`. Where the addon means the same thing as the core, the key is simply dropped: `User` in the audit log column now reads Statamic's "Benutzer:in".

`Dashboard` and `Settings` are dropped for the third reason. Their values were identical to statamic/cms's, so nothing anyone sees changes — but a duplicate that agrees today is a disagreement waiting for one side to be edited, and the hub's detector only reports keys where the values differ. Those two would have gone unreported until the day somebody changed one.

**`Enabled` stays, deliberately.** statamic/cms does not define it at all, so dropping it would leave the string untranslated in the German CP. It is also shipped by `goldnead/statamic-leadhub` with the identical value, which is a shared word rather than a defect: nothing a user sees changes whichever package wins.

`tests/Unit/TranslationKeyOwnershipTest.php` is the guard. statamic/cms is a hard dependency of this package, so its dictionary is readable from inside this suite and the check needs no hub — it fails on any key this addon ships that statamic/cms also owns, naming whether the value REPLACES the core's or merely duplicates it. Against the file before this release it reports all four. It also pins the other half of the rename: a source string changed in the code but not in the dictionary leaves the CP untranslated, and `__()` reports that by silently returning the key.

**What this cannot see, and where it is seen instead:** the siblings. A package cannot read what its neighbours register, only what the core does. The hub compares all installed packages at once, in `tests/Feature/GlobalTranslationDictionaryTest.php`, which is where this finding came from.

## 1.5.6 — 2026-07-28

### Added — the route parameter names are checked against the rest of the family

No defect in this addon, and no change to a single route. What is added is the check that would have caught one.

`Route::bind()` is registered on the router, not on a package. The binding this addon registers for `{automation}` applies to every route with an `{automation}` parameter in every other addon installed beside it, and every sibling's binding applies here in the same way. Nothing warns, nothing logs, and the losing route does not fail loudly: it resolves its id against a repository that has never heard of it and returns 404.

`goldnead/statamic-leadhub` 1.8.0 shipped `/scoring/{rule}` while `goldnead/statamic-webhook-manager` binds `rule` to its own rule repository. On the production hub, which has both, editing or deleting a scoring rule did nothing at all and said nothing at all, through a release.

**Why a green suite did not find that, and why this addon's suite was better placed than most.** Two things have to hold for the failure to be observable in an addon's own bed: the sibling's binding has to exist there, which it never does, and the bed has to mount the CP routes with `SubstituteBindings`, which is the middleware that applies a binding at all. LeadHub's bed had neither. This one has had the middleware since it needed it for its own `{automation}` binder — so a test *could* be written here, and now one is, which also names the property instead of leaving it implied in twelve other tests that happen to depend on it. Taking the middleware back out of `tests/TestCase.php` fails 13 of the 362 tests: the new first case, which is about the property itself, and twelve that only ever exercised it as a side effect of resolving an automation.

The test reads this addon's parameter names out of `routes/cp.php` — string literals only, so the example URLs in the comments are not mistaken for routes — and checks them two ways. The first is exact: a hand-maintained list of names that packages installed beside this one bind application-wide, read off the running hub, and the failure message names the package that would swallow the route. The second is a judgement call made explicit: `handle`, `run` and `source` are generic enough that a sibling could claim one tomorrow, so they are recorded in the test with their reason, and a *new* generic parameter fails until somebody renames it or writes down why it stays.

**What this cannot do.** A collision only exists once two packages are installed together, and no package can see its siblings from inside its own suite. The reserved list is a snapshot maintained by hand; it will not catch an addon that starts binding a name nobody binds today. `automation` is one such name — this addon binds it, so it owns it, but a sibling that ever routes an `{automation}` of its own will silently be handed automations from here. The hub remains the only place the real answer is measurable.

This addon's six parameters (`automation`, `handle`, `run`, `source`, `nodeRun`, `timestamp`) collide with nothing bound elsewhere.

## 1.5.5 — 2026-07-28

The four builder defects 1.5.4 reported and left standing, plus the extraction
it said they needed first.

### Changed — the graph mutations moved out of `Edit.vue`

`resources/js/composables/useGraphMutations.js` now owns every way the builder's
graph can change: add, insert on an edge, append, duplicate, delete, rename,
reconfigure, enable/disable, replace the trigger. `Edit.vue` keeps what is
genuinely the page's — pick mode, selection, save/validate/test, the editor's
measured height — and drops from 986 lines to 737.

This is a means, not the point. Those functions are where the builder's
invariants live (a node key must be unique, an edge must leave an output its
node actually has, one mutation must cost exactly one undo step), and while they
sat inline in the page component the only way to exercise them was to open a
browser and click. Three of the four defects below are in code that was
never reachable from a test. The behaviour is unchanged where it was right; what
changed is that it can now be asserted, and the assertions are what the rest of
this entry rests on.

Two things surfaced during the move that were correct only by luck:

- **`insertOnEdge()` had the same hard-coded `'default'` as `duplicateNode()`,
  on a path nobody had reported.** Dropping a node onto an existing edge wires
  the new node onward to the old target — and did so from an output called
  `default`, whichever node had just been inserted. For every node type in the
  library except three that is the right handle, which is why it never showed:
  insert a **branch** on a "+" between two steps and the second edge was
  invalid in exactly the way duplicating one was. Both now ask the node.
- **`hasTriggerNode()` had no callers.** The one-trigger rule moved into
  `useFlowGuards.js` in 1.2.0 and the function stayed behind, still reading
  correct, still describing the rule in a comment two other functions rely on.
  Deleted.

### Fixed — the history recorded one snapshot per keystroke

A node's name and its config fields are text inputs, and every keystroke reached
`history.record()`. The stack holds 100 entries, so roughly a hundred typed
characters pushed every structural step out of it. Delete a node, type a name,
press undo: the delete is not in the stack any more, and no amount of pressing
undo brings the node back. The stack was longest precisely when it was least
useful.

`record()` now takes an optional tag, and consecutive records carrying the same
tag within 600 ms fold into the entry the run started with. The cut:

- **Structural steps are never folded.** An untagged `record()` — add, delete,
  duplicate, connect, replace trigger, enable/disable — always gets its own
  entry, whatever precedes or follows it. Those are the steps a user means when
  reaching for undo, and none of them can be repeated fast enough to be one
  gesture anyway.
- **Text folds per field, per burst.** The tag names what is being edited
  (`label:<node_key>`, `config:<node_key>`), so moving to another field or
  another node ends the run mid-typing, and so does a pause longer than the
  window — which is where a user's own sense of "one edit" ends.
- **Undo, redo and reset end the run**, so typing after an undo cannot fold into
  the entry that undo just restored past.

600 ms is the usual keystroke-coalescing window, and the clock is injectable, so
the window is asserted rather than waited out.

### Fixed — undo walked behind the last save

`useHistory.reset()` was exported since it was written, documented as
"re-baseline after a fresh load / save", and never called. Undo therefore
reached across a save into edits the user had already committed past — and the
Save button then offered to write that older graph back, with nothing on screen
saying so.

An explicit Save now resets the stack. A background autosave deliberately does
not: it fires two seconds into a pause while the user is still working, and
wiping the undo stack under a running edit is worse than the thing being fixed.
The re-baseline is tied to the moment the user says "save", which is the moment
they mean it.

### Fixed — duplicating a branch produced a graph the validator refuses

"Duplicate" appended the copy on the source node's `default` output. A `branch`
has `true` and `false`; a `loop` has `loop` and `done`; an inline `parallel` has
whatever handles its branches are configured with. None of them has a `default`,
so the new edge left a handle that does not exist: invisible on the canvas (Vue
Flow cannot resolve the source handle), never followed at run time
(`WorkflowRunner::nextNode()` matches `from_output` exactly), and rejected by
`FlowValidator` with `branch_invalid_output`. One click on Duplicate, one
invalid automation, and the "issue" it reports names a node the user never
touched.

Insertions now ask the node for its first declared output instead of assuming
one, so a duplicated branch hangs off `true` and its own onward edge leaves
`true` as well. A node that declares no outputs at all — a `stop`, or a
`parallel` whose branches are not configured yet — gets no edge invented for it:
duplicating it adds the copy unconnected, with a toast saying so, and inserting
one onto an existing edge no longer leaves a dead edge behind it. That last one
is a behaviour change on a path that previously produced an edge which was
already invisible and already unroutable.

### Fixed — `newNodeKey()` never looked at the keys already in use

Four random base-36 characters, no collision check, against a
`unique(automation_id, node_key)` in the schema. The odds are small and they are
not zero — a birthday collision at a few hundred nodes of one type, and a plain
accident at any size — and the failure mode is the worst available: an SQL error
on save, on a graph the user has already finished building, with no way to tell
which node is the problem. Nothing in the editor could have shown it, because
nothing in the editor knew.

`uniqueNodeKey()` draws against the keys the automation already holds. Keys keep
the shape they have always had; the draw simply repeats when it collides, and
falls back to a counter so the loop provably terminates. With a frozen RNG the
old code produces two nodes with one key (and a self-referencing edge for good
measure); the new one produces two keys.

### Changed — the canvas knows the namespaced `*.branch` types the validator has always known

`FlowValidator` has required `true`/`false` on any node type ending in
`.branch` since the first release. Nothing else in the package had heard of the
convention. What that meant in practice, once traced: a third-party addon
registering `acme.branch` got a single `default` output from the canvas, the
user wired the only handle on offer, and validation then refused the graph. The
suffix did not enable a custom branch node — it made one *less* usable than any
other custom node type, and no first-party type has ever ended in `.branch`, so
nothing ever ran into it.

`outputsFor()` now applies the same rule, which is the whole of the alignment on
the canvas side: edge labels, handle positions and the "+" adders all derive
from the output list, and `WorkflowRunner` needs no counterpart at all — it
routes on the output handle a node returns, never on the node's type. The
previous release left this alone on the grounds that it might be half a feature;
the half that is a feature is a different one, and is named under Notes.

### Notes

- **Still open, and genuinely a feature this time: node outputs are declared
  twice.** `SwitchNode`, `ParallelNode` and `LoopNode` each declare `outputs()`
  in PHP, and `outputsFor()` in `useAutoLayout.js` mirrors all three by hand,
  including how they read `config.cases` and `config.branches`. The mirror is
  accurate today and the tests pin it, but `NodeRegistry::describe()` does not
  expose `outputs()`, so a third-party node cannot declare handles of its own —
  it gets `default`, or `true`/`false` if it is named for it. Making the canvas
  read the outputs from the library payload means changing `describe()`, handing
  config-dependent outputs to the frontend, and versioning that payload. Worth
  doing, and not something to start inside a bug-fix release.
- **Duplicate attaches the copy to the source's *first* output**, which for a
  `loop` means the copy lands inside the loop body rather than after it. Valid,
  deterministic, and arguably not what the user meant; there is no metadata
  marking a node's "main" continuation, and inventing one is the same feature as
  above.
- **Known and unchanged:** `SubAutomationAndAlertsTest > failure alerter logs
  and throttles` fails under MySQL on a foreign-key violation
  (`automation_id=999`, which SQLite does not enforce). Reproducible at 1.5.3.
- Suite: **359 passed (1269 assertions)** on SQLite, unchanged — nothing on the
  PHP side moved. Vitest **30 passed**, baseline 11: the graph mutations
  (15) and the builder page driven through its own canvas and header stubs (4).
  `node:test` **81 passed**, baseline 73. Every fix was verified by stashing the
  source and watching its test fail against 1.5.4: the undo returned `Welco`
  instead of the deleted node, undo stayed enabled after the save, the duplicated
  branch left two edges on `br`, and five nodes carried four distinct keys.

## 1.5.4 — 2026-07-28

### Fixed — the handle unique did not constrain anything without a brand

Since 1.5.0 the automation handle is unique per brand: `unique(brand_id, handle)`. The column it leads with was added nullable, and **a SQL unique does not constrain NULL** — on any engine. Two rows that differ only by a NULL in an indexed column are both accepted, and there is no limit to how many. So for every `automations` row without a brand_id, the one identifier this addon promises to keep unique was not constrained at all: the handle could repeat freely, and `Automation::where('handle', …)->first()` would return whichever row the engine happened to reach first.

The models stamp brand_id on create, which is why the hole never opened in normal use. It is reachable from everything that writes the table without going through Eloquent — an import, an upsert, a data fix from tinker — and this package's own test fixture did exactly that, inserting automations rows with no brand_id for a year without anything noticing. A constraint that holds only while every future writer remembers something is not a constraint.

**Why a green suite would never have found it.** Not because the assertion was missing, but because the thing to assert is invisible from the test's vantage point. The suite runs on in-memory SQLite, where the schema is never measured and NULL-permeability is not a property anything reports; the addon's own fixture inserted the NULL rows and the tests passed, since there were never two of them with the same handle. Nothing fails until a second row arrives, on a host, months later, and then it does not fail either — it resolves to the wrong automation. `statamic-notifications` v1.0.4 found the same shape in its preferences table, where an entire recipient type had been unconstrained since it shipped.

`automations.brand_id` is now NOT NULL. `2026_07_24_100002` tightens it where it creates it, which helps new installations only; `2026_07_28_000003_require_brand_id_on_automations_table` is for the ones already on 1.5.x. It is idempotent, a no-op on a fresh install, and it renames rather than deletes any duplicate handles it has to separate before the backfill — an automation is somebody's work, and a suffixed handle is visible and fixable where a deleted flow is neither. Renames are written to the log.

Only `automations` is tightened. The denormalized brand_id on the child tables stays nullable: none of them carries a unique, and changing a column's nullability on MySQL rebuilds the table with `ALGORITHM=COPY` — a fair price on `automations`, which holds one row per automation, and the wrong one on `automation_runs`, which grows without bound. Tenant separation is unchanged and asserted rather than assumed: two brands can still hold the same handle, and one brand still cannot hold it twice.

### Fixed — the handle validation was still global, three releases after the schema stopped being

The mirror image of the same question, found by asking of every unique whether it enforces what its name claims. `StoreAutomationRequest` and `UpdateAutomationRequest` still used `Rule::unique('automations', 'handle')`, which compiles to a query on the raw query builder that no Eloquent global scope ever reaches, and is therefore global.

Two consequences, both silent, both in the direction of the validator being stricter than the database. A brand could not create an automation with a handle another brand had already taken, although the schema has allowed exactly that since 1.5.0 and that was the entire point of the change. And the refusal named the reason: *"The handle has already been taken"* is a statement about rows the asking tenant is not permitted to see. Both rules now carry `->where('brand_id', …)`.

### Added — the suite can see MySQL's index rules

`tests/Unit/IndexKeyLengthTest.php`, ported from `statamic-notifications` v1.0.4 by way of `statamic-webhook-manager` v1.6.1, compiles this package's own migration files through Laravel's MySQL grammar in pretend mode and measures the DDL MySQL would have received — no server, no connection, nothing to install in CI. It reads the real migration files, so it cannot drift from them, and it needs the extended version: this schema is built across eleven migrations, and `brand_id` arrives by `alter table … add` long after the create migrations, together with the drop of the global handle unique and the per-brand one that replaces it.

It asserts three things: no index over InnoDB's 3072 bytes; no index over **half** of it, because an index that is under the limit by accident breaks on the next column added to it; and no unique covering a column that may be NULL — the check that failed above.

**What the measurement says about the width.** Sound, and sound by luck rather than by check until now. The widest index is **1028 bytes**, 33% of the limit, shared by `automations_brand_id_handle_unique`, `automation_nodes_automation_id_node_key_unique`, the two `automation_edges` node-key lookups and `automation_runs_status_created_at_index`. Nothing is near the wall. `statamic-notifications` v1.0.3 shipped a 3212-byte unique that had run hundreds of times locally and died on the production hub with *SQLSTATE 1071*, leaving two tables that never existed there — the arithmetic that rejects it is a MySQL mechanism and does not exist in SQLite to be tested.

`phpunit.mysql.xml` runs the identical suite against a real MySQL server (`vendor/bin/pest -c phpunit.mysql.xml`, `AUTOMATIONS_TEST_DB=mysql`), for the run that proves the compiled DDL and the engine agree.

### Added — a test level for the Control Panel's Vue code (Vitest)

The package had two test levels and a gap between them. PHPUnit reaches the route, the FormRequest, the controller and the props it hands to Inertia; `tests/js/*.test.mjs` reaches the builder's pure functions. Neither could mount a component, and the builder keeps most of its state there.

Rolled out from `statamic-webhook-manager` v1.6.0: the `test` block lives in the existing `vite.config.js` (under `VITEST` the Statamic Vite plugin is swapped for the plain Vue plugin, because the former rewrites `vue` to `window.Vue` — correct for the CP bundle, fatal in a test process), `tests/js/setup.js` installs the `__STATAMIC__` global the `@statamic/cms/*` shims destructure at import time, and the new dependencies are `vitest`, `@vue/test-utils` and `jsdom`. Two additions to that setup were needed here: `__` is installed on `globalThis`, because this addon's components call the translator from `<script setup>` and not only from templates, and the stubs forward event listeners, without which a stubbed `<Button>` cannot be clicked and no interaction is testable. `npm test` runs the component suite; `npm run test:js` keeps running the pure-function one.

### Fixed — four node types had a setting the editor silently swallowed

`ConfigPanel` filtered `mode` out of every generic field form unconditionally, because for `filter`, `branch` and `wait_until` the `mode` field *is* the all/any selector that `ConditionBuilder` renders instead. But `ConditionBuilder` only mounts when the schema declares `conditions`, and four node types declare a `mode` and no conditions. Their setting was removed from the form and nothing put it back:

- **`add_user_to_group` and `assign_user_role`** — "Remove from group" and "Remove role" could not be configured at all. The panel offered the group or the role and nothing else.
- **`parallel` and `loop`** — the inline/automation switch was unreachable, and on `parallel` that setting decides the node's entire output set.

`defaultConfigForSchema` seeded the default (`add`, `inline`), so every affected node validated and looked complete. `mode` is now filtered only where there are conditions for it to combine.

### Fixed — an edge output handle could be stored as an empty string

`edges.*.from_output` is `['nullable', 'string']`, so `""` is valid input, and every write path normalised it with `$edge['from_output'] ?? 'default'` — which substitutes for a *missing* key, not for a present empty one. Stored, the edge is invisible on the canvas (Vue Flow cannot resolve `sourceHandle: ""` against a handle called `default`, and the source node still shows an unused "+" adder on the output it is already wired to) and dead at run time: `WorkflowRunner` selects outgoing edges with `$e->from_output === $output`, so the edge is never followed. The run reports success and stops one node early, with nothing to show for it.

A CP save was protected by accident — Laravel's `ConvertEmptyStringsToNull` turns the cleared field into null before the FormRequest sees it. An import reads JSON off disk, where `""` stays `""`. `AutomationEdge` now normalises both handles on write, so every path gets the same guarantee, including the ones added next.

### Fixed — `??` where `||` was meant, in the builder and the CP pages

Twenty-two sites, judged one at a time rather than replaced wholesale: a good half of this package's `??` is load-bearing and a `||` there would be the defect (`positions[key]?.x ?? cursor` must keep a legitimate `0`; `config[handle] ?? field.default ?? ''` must keep a stored `false` from a toggle and a `''` the user deliberately cleared; `is_test ?? null` must keep the `0` that means "non-test runs only"). The ones that were wrong:

- **`from_output ?? 'default'`** (nine sites) — the reading half of the defect above.
- **`data?.message ?? __('… failed.')`** (eleven sites) — a server `message: ""` rendered a blank error toast instead of the readable fallback. `Edit.vue` was already internally inconsistent about this: its validation branch used truthiness, its message branch did not.
- **`schema?.label ?? node.type`** — a node class whose `label()` returns `''` produced an empty name placeholder, while the heading directly above it fell back correctly.
- **`props.queue ?? 'default'`** — an empty `STATAMIC_AUTOMATIONS_QUEUE=` in `.env` rendered the Settings row blank, reading as if no queue were configured.

### Fixed — the config panel carried the previous node's state to the next one

`Edit.vue` mounts the panel with `v-if`, not `:key`, so selecting another node reuses the same component instance. Three defects followed from that, all of them invisible outside a browser:

- **The template picker wrote to the wrong field.** `emailFieldHandle` is the handle `onEmailTemplateSelected` calls `setField()` with; left pointing at the previous node's field, a template picked after a node switch landed on a handle the current node may not even have. The three modal refs now reset when the selected node changes.
- **Key-value rows leaked between nodes.** The field loop was keyed by `field.handle` alone, so two nodes with a `headers` field shared one `KeyValueField` — and that component keeps its rows locally, resyncing only when the incoming value differs from what it last emitted. Two empty maps serialise identically, so the resync was skipped: the previous node's half-typed rows stayed on screen and the next keystroke committed them onto the new node. The loop is now keyed by node *and* handle.
- **`KeyValueField` read its labels once.** `const keyLabel = props.keyLabel ?? __('Key')` was evaluated at setup, and ConfigPanel passes those labels conditionally, so the placeholders kept describing whichever field mounted the component first. Now computed.

### Notes

- **The Vue review found no live instance of the other class this QA round looked for** — a string method on a value the backend can also send as an array or null. All 34 call sites were traced to their receiver; the ones reading node config, run output and error payloads are guarded by an explicit `typeof … === 'string'` or a `String()` coercion, and `error_message` is only ever interpolated. The nearest thing to a hole is `NodeLibrary`'s `item.label.toLowerCase()`, which would throw while typing in the filter box if a third party registered a node with no `label` in its meta — not reachable through any first-party path, and left alone rather than patched into a finding.
- **Known and unchanged:** `SubAutomationAndAlertsTest > failure alerter logs and throttles` fails under MySQL on a foreign-key violation (`automation_id=999`, which SQLite does not enforce). Reproducible at v1.5.3.
- **Still open, reported rather than fixed:** history records one snapshot per keystroke, so ~100 characters of typing evicts every structural undo from the 100-entry stack; `useHistory.reset()` is exported and never called, so undo reaches past a save; duplicating a `branch`, `loop` or `parallel` node appends the copy on a hard-coded `default` output that those node types do not have, which `FlowValidator` then rejects; and `newNodeKey()` has no collision check against `unique(automation_id, node_key)`. All four live in graph mutations inlined in `Edit.vue`; pinning them wants those extracted into a composable first, in the style of the ones already tested.
- Suite: **359 passed (1269 assertions)** on SQLite, baseline 343. Vitest **11 passed**, `node:test` unchanged at **73**.

## 1.5.3 — 2026-07-28

### Fixed — Delay nodes saved before 1.5.2 stayed invalid

1.5.2 fixed the *writing* side of the config panel: a Delay node created from
then on carries `{"amount":1,"unit":"minutes"}` instead of a default that was
only ever painted on screen. It left the rows that were already on disk alone,
and said so in its notes. That is the part this release finishes.

A Delay node saved before 1.5.2 has an `amount` and no `unit`. It runs —
`DelayNode::execute()` falls back to minutes — but the editor marks it red with
"This field is required." under a select that visibly reads "Minutes", and the
only way out was to open the node and re-save it. On an install with a few
dozen automations that is busywork with no decision in it, which is exactly
what a migration is for.

The new migration writes `unit: minutes` into Delay node configs that have
none. `minutes` is not a preference — it is the value the node has been
behaving as all along, and a test pins the migration's constant to
`DelayNode::execute()` rather than to a comment, so the two cannot drift apart.
The migration touches only `type = 'delay'`, only rows without a usable `unit`,
decodes and re-encodes the rest of the config unchanged, and leaves
`updated_at` alone: this is a repair, and that column should keep answering
"when did a human last change this node?".

It deliberately has no `down()`. Reversing it would mean deleting `unit` from
Delay configs, and the migration cannot tell the rows it wrote from the ones a
user has since set to "hours" or "days" — a rollback would strip real settings
to restore a state whose only property was being broken.

**Multi-brand:** the migration goes through the query builder, not the
`AutomationNode` model. The model carries the fail-closed `HasBrand` scope, and
a migration runs with no brand in context — via the model it would have matched
zero rows and silently skipped every tenant. Brand isolation is a request-time
boundary; a migration runs beneath it, across the whole table, once. `brand_id`
is neither read nor written, and each row is completed from its own config.

**Flat-file installs are not covered.** With
`automations.storage.driver = flatfile` the nodes live in YAML and there is no
table to migrate; those still need the one-time re-save from the 1.5.2 notes.

### Changed — run timestamps now keep their milliseconds

`started_at` and `finished_at` on runs and node runs were whole-second
`timestamp` columns. Two nodes that ran 40 ms apart came back with the same
stored instant, and the only surviving evidence of the difference was
`duration_ms` — enough to render a duration, not enough to sort or correlate by
point in time. 1.5.2 noted this as a known limit; it is now lifted.

The column change alone would not have done it. Eloquent serialises every
date-castable attribute with the *connection's* format, which is
`Y-m-d H:i:s` on every driver Laravel ships, so the fraction was being dropped
in the model before the column was ever consulted. The four attributes now cast
through `MillisecondDateTime`, which writes `Y-m-d H:i:s.v` and reads both
shapes — rows written before this release parse unchanged. The cast is scoped
to those four attributes on purpose: setting `$dateFormat` on the model would
have been shorter, but it also applies to `created_at` / `updated_at`, which
are whole-second columns that MySQL would then round by up to half a second.

**SQLite is skipped by the migration, on purpose.** SQLite has no typed
datetime — Laravel maps `timestamp` and `timestamp(3)` both to `datetime`,
stored as text — so `->change()` there produces a column byte-for-byte
identical to the one it replaced. What it *does* do is rebuild the whole table:
create a temp table, copy every row, drop, rename, recreate the indexes. Paying
a full table copy on a growing table for a no-op is the wrong trade, so the
migration reports the skip instead of performing it. SQLite installs still get
millisecond timestamps; there the precision comes entirely from the string the
cast writes, which SQLite stores and returns verbatim.

**MySQL note for large installs.** Changing a `TIMESTAMP` column's
fractional-seconds precision cannot be done in place — MySQL rebuilds the table
with `ALGORITHM=COPY` and blocks writes for the duration. On a runs table of
any size, run this in a maintenance window or through
`pt-online-schema-change`. The 2038 limit of `TIMESTAMP` is unchanged; these
columns were already `timestamp` and stay that way.

### Testing

- The suite can now be pointed at a real MySQL server:
  `AUTOMATIONS_TEST_DB=mysql DB_HOST=… vendor/bin/pest`. Both new areas were
  verified on SQLite and on MySQL 8.4, because the two disagree about precisely
  the things this release touches — typed datetimes, fractional seconds, and
  what `->change()` does.
- One back-fill test is skipped on MySQL: a `json` column refuses to store
  malformed text, so the migration's "leave unparseable config alone" guard
  cannot be provoked there. It still matters on SQLite and on legacy text
  columns.

## 1.5.2 — 2026-07-27

### Fixed — a resumed run reported a negative duration

Three defects stacked into the CP showing `DURATION -652 ms` on every run with a delay:

- **The resume job restamped `started_at`.** `RunLogger::startRun()` is called again when `WorkflowRunner::resumeAfterNode()` picks a run back up after a Delay / Wait. It overwrote `started_at` with the resume moment, so the run's real origin — and with it the entire wait — was gone from the record. `started_at` is now stamped once, on the first start only.
- **`duration_ms` was computed backwards.** `$finished->diffInMilliseconds($started)` reads as "finish, then diff to start", and Carbon 3 returns a *signed* difference, so a well-ordered start/finish pair yielded a negative number. Carbon 2 returned the absolute value, which is why this only surfaced after the Carbon 3 bump. All duration arithmetic now goes through `RunLogger::elapsedMs()`, which computes in the natural direction and clamps at zero, so a stored duration can never be negative — not even when the wall clock steps backwards mid-run.
- **`duration_ms` is stored in an `unsignedInteger` column.** SQLite accepted the negative value and handed it straight back to the UI; MySQL in strict mode would have rejected the write instead. Either way the value was wrong at the source.

**What "duration" means for a waiting run:** `duration_ms` is **wall clock — first start to final finish, waiting time included.** A run that sits in a 3-day delay reports 3 days. This is the reading that matches what is stored (`finished_at - started_at`), and the one that answers the question the runs list is actually asked: "when did this finish relative to when it was triggered?" Pure compute time is not lost — it is the sum of the node durations, which the next item makes truthful for the first time.

### Fixed — every node run reported `0 ms`

`RunLogger::recordNodeRun()` set `started_at` and `finished_at` from two `now()` calls taken *after* the node had already executed, so the only interval it ever measured was the time to build the record itself. The answer to "is the node duration measured or just not displayed?" is that it was never measured. The runner now captures the start before executing the node and passes it in.

Note: `started_at` / `finished_at` are plain `timestamp` columns (whole-second precision), so a sub-second node still collapses to a single stored second. The millisecond value lives in `duration_ms`, which is computed before persisting — that is what the CP renders.

### Fixed — the Delay node was permanently "Invalid"

The `unit` field is required and declares a default of `minutes`. The config panel *rendered* that default (`config[handle] ?? field.default`), so "Minutes" was on screen, but a rendered fallback is not a model value: `config.unit` stayed undefined, inline validation flagged a missing required field, and the node stayed red under a visibly pre-filled select — reporting "This field is required." about a field that looked filled. Only re-picking the very same option wrote it into the model. Newly created nodes (and trigger replacements) now seed every schema-declared default into their config, so what the panel shows is what the model holds and what gets persisted.

Behaviour was never affected — `DelayNode::execute()` falls back to minutes — but the node is now green without user interaction, and `{"amount":1,"unit":"minutes"}` is what actually gets saved.

### Notes

- Existing saved nodes are not migrated. A Delay node saved before this release still has no `unit` in its config and will keep showing as invalid until it is opened and re-saved. It continues to run as minutes.
- `resources/dist/build` was already out of date with its source at 1.5.1, independently of these changes; this release ships a matching rebuild.

## 1.5.1 — 2026-07-27

### Fixed — `automations:run-scheduled` was left out of the 1.5.0 fix

Its `handle()` takes an injected service, and the transformation that added the `forEachBrand` call only matched parameter-less signatures. The command imported the trait and never called it — still blind under multi-brand.

## 1.5.0 — 2026-07-27

### Fixed — scheduled commands did nothing under multi-brand and reported success

- **`automations:run-due` never resumed a delayed step.** A scheduled run has no session and therefore no brand; the fail-closed scope hid every row, so the command reported "Dispatched 0" while a due job sat there indefinitely. Delays simply never continued. `automations:run-scheduled` and `automations:prune` had the same defect.
- All three now iterate the brands via `RunsForEachBrand` from `goldnead/statamic-brand-context` ^1.3, and each accepts `--brand=<handle|id>` to restrict. Single-brand installs are unaffected — the work runs once, in the ambient context.

### Notes

- Found in the hub QA run: `DB::table('automation_runs')->count()` returned 1 while `AutomationRun::count()` returned 0, with `multiBrand=true hasCurrent=false failMode=closed`.
- The silent shape of this failure is the dangerous part: nothing errors, nothing is logged, and the scheduler keeps reporting healthy runs forever.

All notable changes to **Statamic Automations** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.3] - 2026-07-02

### Fixed
- **LeadHub and Webhook Manager action nodes failed against real facades.**
  The adapters guarded every call with `method_exists()` on the configured
  facade class and then called it statically. Real Laravel facades (like
  `Goldnead\Leadhub\Facades\LeadHub`) proxy all calls through
  `__callStatic`, so `method_exists()` was always false and every action
  failed with e.g. "LeadHub facade does not implement createTask()" on real
  installs. The adapters now resolve the facade root instance via
  `getFacadeRoot()` when the configured class is a Laravel facade, and probe
  and call methods on that instance. Plain classes with real static methods
  and pre-built service objects keep working unchanged.

### Added
- Regression tests that exercise both adapters through a real
  `Illuminate\Support\Facades\Facade` subclass backed by a container-bound
  manager instance, alongside the historic plain-static-class fakes.

## [1.0.2] - 2026-07-02

### Fixed
- **CP list page row actions 404ed** (delete, enable/disable, duplicate,
  export JSON). The Automations index page passed the `…/api/automations`
  listing URL as `apiBase`, while the Vue page appends `/automations/{id}/…`
  itself — every row action therefore hit
  `…/api/automations/automations/{id}` and returned HTTP 404. The page now
  passes the API root (`…/api`), matching the builder (Edit) page. Affected
  both storage drivers; the `{automation}` route binding itself was fine and
  accepts ids and uuids for either driver.

### Added
- Regression tests that drive the index-page row actions exactly like the
  frontend does (render the Inertia page, build the URL from its real
  `apiBase`/`rows` props, send with XHR headers) for delete, enable/disable,
  duplicate and export, in both database and flat-file storage modes.

## [1.0.0] - 2026-06-30

First public release on the Statamic Marketplace.

### Editions & licensing
- **Free / Pro editions** declared via `extra.statamic.editions`. Pro features
  (the AI action, custom node registration) are gated through Statamic's native
  Marketplace licensing (`Addon::edition()`), with local `config`/`remote`
  modes kept as a self-hosted fallback.
- Commercial software license (replaces MIT).

### Added — feature set
- **Triggers:** manual, form submitted, entry published/saved/deleted, user
  registered, scheduled (cron via `automations:run-scheduled`), and a
  `webhook_received` bridge to Webhook Manager.
- **Actions:** send email, send webhook, add log entry, create/update entry,
  create user, set variable, call automation (sub-flows), and `ai_generate`
  (Anthropic Claude Messages API, **Pro**).
- **Control flow:** filter, branch, switch, stop, delay, wait-until, loop
  (for-each), parallel (fan-out/join), throttle/deduplicate.
- **Expressions:** `TokenResolver` pipe filters
  (`lower|upper|ucfirst|title|trim|slug|length|json|default|date`).
- **Reliability & ops:** overview dashboard (KPIs + trend + recent failures),
  throttled failure alerts, per-node retries + on-error-continue policy,
  node-by-node run logs with redaction.
- **Versioning** via Statamic Revisions (flat-file), with rollback; **audit
  log** with a native CP screen.
- **Storage drivers:** `database` (default) or `flat_file` (one YAML file per
  automation); runtime data always in the database.
- **Platform:** secrets store (`{{ secret.* }}`), i18n (English + German),
  per-node inline testing, importable template catalog.

### Changed — Marketplace-readiness pass (align with LeadHub)

Brought the addon in line with the sister LeadHub addon's launch-grade
conventions and fixed two Control-Panel launch blockers.

- **Statamic 6 Vite convention.** Switched `vite.config.js` to the official
  `@statamic/cms/vite-plugin` + `laravel-vite-plugin` (publicDirectory
  `resources/dist`), made `@statamic/cms` a `file:` npm dependency, and
  replaced the ServiceProvider's `$scripts`/`$stylesheets` with the `$vite`
  property. The compiled CP assets are now committed under
  `resources/dist/build/` and published automatically on install — no
  end-user build step.
- **CP routing launch blocker.** Routes are now registered via
  `$routes = ['cp' => ...]`, so Statamic mounts them under `/cp` with the
  `statamic.cp.` name prefix and CP auth middleware. The controllers and nav
  already used `cp_route('statamic-automations.*')`; the previous manual
  `loadRoutesFrom` registered bare names, which made every `cp_route()` throw
  and 500 the page.
- **Controller bug fixes** (caught by new CP smoke tests):
  `NodeRegistry::describeAll()` → `all()`, and the Settings page's
  `LicenseManager::isValid()` → `status()` check.
- **composer.json:** Statamic `^6`, `laravel/framework ^11|^12|^13`,
  `inertiajs/inertia-laravel`, Pest dev dependencies; dropped Statamic 5
  (the CP is Inertia/Vue 3 / `@statamic/cms`, which is v6-only).

### Added

- **Pest test harness** mirroring LeadHub: `TestCase` registers the real
  Statamic service provider, forces `bootAddon()`, mounts the real CP routes,
  and uses `RefreshDatabase` + real Statamic super users (the NoopAuth /
  `TestServiceProvider` test hacks are gone).
- **`CpRoutesTest`** renders every Inertia CP page and **`ApiSmokeTest`**
  exercises every JSON endpoint the Vue builder calls. **91 tests pass.**
- **`scripts/setup-playground.sh`** — builds a persistent, runnable Statamic 6
  playground with the addon wired in as a path repo; `.devcontainer`
  delegates to it.
- **`MARKETPLACE.md`** listing copy and `.gitattributes` dist-export rules.

### Fixed — Sprint 7 (full PHPUnit suite green, no skips)

After running the test suite end-to-end inside a real PHP+Composer
sandbox (with `statamic/cms` v6.18.0 actually installed), the
previously skipped HTTP API test could finally be diagnosed:

- `withoutMiddleware()` in `AutomationsApiTest::setUp` was disabling
  every middleware including `Illuminate\Routing\Middleware\SubstituteBindings`,
  so implicit route-model binding for `{automation}` silently returned
  an empty Eloquent instance with `id = NULL` — and the
  `WorkflowRunner::createRun` insert hit the `automation_id` FK.
- Replaced the previous alias-to-noop with a proper
  **middleware group** that wires both a no-op auth shim AND
  `SubstituteBindings`. Route-model binding now resolves to the real
  Automation row inside HTTP feature tests, the test passes, and the
  `markTestSkipped` is removed.
- New build dependency: a real PHP CLI environment with `statamic/cms`
  installed. The CI matrix already provides this; for local runs see
  the `statamic-6-phpunit-sandbox` skill recipe.

**Result**: 71 tests / 223 assertions / 0 failures / 0 errors / 0 skipped.

### Changed — Sprint 6 (Statamic 6 CP UI Patterns)

The CP frontend has been completely rewritten on top of **Statamic 6's
native Inertia.js + Vue 3 + Tailwind v4 stack**, following the official
[Statamic 6 CP UI Patterns](https://statamic.dev) skill. This is a UI
overhaul, not a feature change — the engine, public API, data model
and JSON endpoints are all unchanged.

#### Architecture

- **Inertia.js pages** registered through `Statamic.$inertia.register()`
  in `cp.js`. No more `data-automations-app` mounting — Statamic's
  Inertia plugin renders our pages inside the native CP layout.
- **All UI primitives** sourced from `@statamic/cms/ui`: `Header`,
  `Listing`, `PublishForm`, `Panel`, `Button`, `Switch`, `Badge`,
  `Alert`, `EmptyStateMenu`, `CodeEditor`, `Stack`, ... — no more
  custom `.sa-*` SCSS for buttons, cards, tables, toasts.
- **Tailwind v4** with the Statamic layer order
  (`base → addon-theme → addon-utilities → components → utilities → ui → ui-states`).
  Dark mode "for free" through Tailwind `dark:` variants.
- **`@statamic/cms/inertia`** for navigation: `<Link>`, `router.visit()`,
  `<Head>` (no more raw `<a href>` or `window.location`).
- **`@statamic/cms`-marked external in Vite** so the addon bundle
  doesn't ship a duplicate Statamic-UI library — uses whatever the
  host install ships with.

#### New CP page tree

| Page | Component |
|---|---|
| Automations list | `pages/Automations/Index.vue` (Listing) |
| Builder | `pages/Automations/Edit.vue` (Header + Vue Flow + Panel sidebars) |
| Runs list | `pages/Runs/Index.vue` (Listing + filters) |
| Run detail | `pages/Runs/Show.vue` (Panels + CodeEditor for context/IO) |
| Templates | `pages/Templates/Index.vue` (Panel cards) |
| Import | `pages/Import.vue` (drop zone + CodeEditor) |
| Settings | `pages/Settings/Show.vue` (read-only Panels) |

#### Backend changes

- **`Pages/*PageController`** classes: `AutomationsPageController`,
  `RunsPageController`, `TemplatesPageController`,
  `ImportPageController`, `SettingsPageController`. Each returns
  `Inertia::render('statamic-automations::Page', [...props])`.
- **GET routes** now hit Inertia controllers; the existing JSON CRUD
  / canvas / actions / runs / templates / settings routes remain
  under `/automations/api/*` and are consumed by the Vue pages via
  axios.
- **CP nav** uses the new route names (`statamic-automations.*`).
- **Asset loading** moved to Statamic's `protected $scripts` and
  `$stylesheets` properties on the AddonServiceProvider.

#### Removed

- `resources/views/cp/*.blade.php` — Inertia renders pages directly.
- All custom UI helper components: `EmptyState`, `LoadingSpinner`,
  `ErrorMessage`, `Toast`, `AutosaveIndicator`, custom `Field*`
  components, the old `useToast` composable, the axios `client.js`,
  `utils/uuid.js`. All replaced by `@statamic/cms/ui` equivalents.
- `resources/sass/automations.scss` — Tailwind v4 only now.

#### Vue Flow canvas

The canvas itself stays as a custom widget (no Statamic UI primitive
matches a node-graph builder). It's slimmed down and lives at
`resources/js/components/builder/` with five files: `Canvas`,
`NodeCard`, `NodeLibrary`, `ConfigPanel`, `ConditionBuilder`,
`RunLogPanel`. All wrapped by Statamic's `<Header>`, `<Panel>`,
`<Button>`, `<Switch>`, `<Stack>` for the surrounding chrome.

#### Migration impact for users

- Existing automations are unchanged; the data model is identical.
- After upgrading you must re-publish the assets:
  `php artisan vendor:publish --tag=statamic-automations-assets --force`.
- The `statamic-automations.*` route name prefix is new — anyone
  who reverse-route-resolved against the old `automations.*` names
  needs to update.

### Fixed — Sprint 5 (CI green-up)

After landing the GitHub Actions workflows the test matrix surfaced
several real bugs that the local sandbox couldn't catch (no PHP
available). Iteratively fixed:

- **Composer plugins blocked**: `pixelfear/composer-dist-plugin`
  (used by Statamic for its CP assets) and `php-http/discovery`
  weren't in the `allow-plugins` allowlist, so Composer 2.2+ refused
  to install them. Added explicit allow-plugins block.
- **Test bootstrap missed `bootAddon()`**: Statamic's
  `AddonServiceProvider` defers `bootAddon()` to a `Statamic::booted()`
  callback that Orchestra Testbench never fires. Introduced
  `tests/TestServiceProvider` that runs `bootAddon()` directly in
  `boot()` so registries / listeners / migrations are available in
  every test, including HTTP-dispatched ones.
- **`WorkflowRunner` resilience**: when callers passed the wrong node
  as the trigger (e.g. `$automation->nodes->first()` returning a
  non-trigger), the walker started from a non-trigger and ran in
  the wrong direction. The runner now verifies that the resolved
  start node is registered as `kind=trigger` and falls back to
  `findTriggerNode()` if not.
- **Pro gate in tests**: `features.custom_actions_requires_pro`
  defaulted to `true`, blocking tests that legitimately register
  custom triggers/actions. Disabled in `TestCase::defineEnvironment`.
- **`--prefer-lowest` matrix entry dropped**: the lowest-resolving
  Orchestra Testbench (9.0.1) is missing API-test plumbing
  (`$latestResponse` static property) that later 9.x releases added.
  Decision documented inline.
- **One HTTP API test parked**: `AutomationsApiTest::test_test_endpoint_runs_automation_in_test_mode`
  hits a route-model-binding edge case under Orchestra that doesn't
  exist in real Statamic. Marked `markTestSkipped` with TODO; the
  same engine path is fully exercised by the WorkflowRunnerTest and
  ManualTriggerTest feature test.

**End state**: 9/9 CI checks green — PHP 8.2 / 8.3 / 8.4 × Laravel
11 / 12 (PHPUnit), Frontend (Vite + Vue 3 build), Lint
(PHP syntax + composer validate).

### Added — CI / DX

- **GitHub Actions** workflows:
  - `tests.yml` runs PHPUnit across PHP 8.2 / 8.3 / 8.4 × Laravel 11 / 12
    plus a lowest-deps job (PHP 8.2 + Laravel 11 + `--prefer-lowest`).
  - `build.yml` runs `npm ci && npm run build`, verifies `resources/dist/cp.js`
    is non-empty, and uploads the built bundle as a 14-day artifact on
    pushes to `main`.
  - `lint.yml` checks PHP syntax across `src/`, `tests/`, `config/`,
    `database/`, `routes/` and validates `composer.json` strictly.
- README now ships **Tests / Build / Lint** status badges next to the
  package metadata.

### Added — Sprint 4 (Roadmap futures)

- **File-backed automations**: `automations:sync` Artisan command with
  `--from=files|db|auto`, `--strategy=db_wins|file_wins`, `--dry-run`
  and `--watch`. Auto-detects sync direction when one side is empty.
- **Run pruning**: `automations:prune` command honors
  `runs.prune_after_days` and `runs.keep_failed_runs_days`.
- **Partial-from-node retry**: `WorkflowRunner::executeFromNode()`
  resumes a run from a specific node. The CP `POST /node-runs/{id}/retry`
  endpoint dispatches a new `RetryFromNode` job and replays prior
  successful node outputs into the new run's context. The Run Detail
  screen exposes a "Retry from here" button per node-run.
- **Encrypted run logs**: `EncryptedJson` cast wraps the encrypted
  payload in a `{ "_encrypted": "…" }` JSON envelope so existing JSON
  columns stay valid. Toggle via `automations.runs.encrypt_context`
  (default off). Legacy unencrypted rows continue to read transparently.
- **License Manager**: `LicenseManager` service supports `config` and
  `remote` modes with caching. Pro gating is opt-in via
  `automations.features.custom_actions_requires_pro`. Built-in nodes
  (including LeadHub + Webhook Manager) are never gated.
  New endpoint: `GET /cp/automations/api/license/status`.
- **Autosave**: `useAutosave` composable with debounced writes (2s),
  topbar toggle and inline status indicator. Skipped silently for
  unsaved automations to keep handle generation a deliberate action.
- **Verified Statamic v6 events**: Listener mapping references the
  documented v5/v6 event class names with explanatory comments.
- **Docs**: `docs/file-sync.md`, `docs/licensing.md`, `docs/autosave.md`.

### Added — Sprint 3 (Phase I + J)

- **Templates**: two new built-in templates — _Lead Magnet Delivery_ and _Follow-up Reminder_.
- **Export / Import**:
  - `AutomationExporter` produces schema-versioned JSON.
  - `AutomationImporter` validates payloads up-front, detects missing integrations and unknown node types, and resolves handle conflicts automatically.
  - `AutomationFileSync` writes / reads `resources/automations/{handle}.json` for Git-based versioning.
  - New endpoints: `GET /automations/{id}/export`, `POST /automations/import`, `POST /automations/{id}/sync-to-file`, `GET /automations/{id}/sync-status`, `GET /automations/file-storage/list`.
  - Frontend: drag-and-drop import page, "Export" button in the builder, "Export" / "Import JSON" buttons on the list screen.
- **Polish**:
  - `EmptyState`, `LoadingSpinner`, `ErrorMessage`, `Toast` components for consistent UX.
  - `useToast` composable for global feedback.
  - Empty / loading / error states on every list and detail screen.
  - Toast notifications on save, validate, test, enable / disable, duplicate, delete, export, retry.
- **Documentation**:
  - Comprehensive marketplace README.
  - `docs/getting-started.md`, `docs/architecture.md`, `docs/extending.md`, `docs/api.md`.
  - This `CHANGELOG.md`.

## [Sprint 2]

### Added

- **Optional integrations** with `IntegrationDetector`: Webhook Manager and LeadHub adapters with conditional registration.
- **5 LeadHub triggers** (Lead Created, Status Changed, Tag Added, Note Added, Follow-up Due).
- **7 LeadHub actions** (Create or Update Lead, Change Status, Add/Remove Tag, Add Note, Create/Complete Follow-up).
- **Webhook Manager** action that delegates to the sister addon's destinations / signing / retry.
- **CP JSON API** — Automations CRUD, validate, enable / disable, duplicate, test, runs, templates, settings, node metadata, dynamic option sources.
- **Vue Flow canvas UI** — schema-driven config panel, token picker, condition builder, run log drawer, validation drawer.
- **Frontend build pipeline** with Vite + Vue 3 + Vue Flow.

## [Sprint 1]

### Added

- Package skeleton (composer, ServiceProvider, config, routes, permissions, navigation).
- 6 migrations for the flow-based data model.
- `AutomationContext`, `TokenResolver`, `ConditionEvaluator`, `FlowValidator`, `WorkflowRunner`, `NodeExecutor`, `RunLogger`.
- 3 built-in triggers (Manual, Form Submitted, Entry Published), 4 logic nodes (Filter, Branch, Stop, Delay), 3 actions (Send Email, Simple Webhook, Add Log Entry).
- Public Facade (`Automations::trigger / ::action / ::node`).
- Unit tests for engine + integration + template registries; feature test for full manual run.
