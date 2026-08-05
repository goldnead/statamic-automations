# Sequences

A sequence — a welcome series, a follow-up, a drip — is an automation. There is
no separate sequence object in this addon and there is not going to be one. What
1.9 adds is three things that make an ordinary automation readable and safe to
run as a sequence: a list view, a re-entry rule, and the enrollment numbers.

## The mail list

Every automation can be read as the list of mails it sends, with the gap before
each one:

```
GET    /cp/automations/api/automations/{automation}/mail-list
POST   /cp/automations/api/automations/{automation}/mail-list
POST   /cp/automations/api/automations/{automation}/mail-list/reorder
DELETE /cp/automations/api/automations/{automation}/mail-list/{nodeKey}
```

`GET` answers:

```jsonc
{
  "mails": [
    {
      "position": 0,
      "node_key": "mail_1",
      "type": "marketing.send_email",
      "label": "Welcome to the choir",     // the subject, per the node
      "reference": "welcome-1",            // what it points at, per the node
      "delay": { "seconds": 0, "sources": [] },
      "conditional": false,
      "condition": null,
      "also_runs": []                      // non-delay nodes in the same gap
    }
  ],
  "editable": true,
  "reasons": [],
  "trigger": "trigger",
  "tail": []
}
```

### The list is a list of the e-mails, not a picture of the automation

That sentence decides everything else on this page.

**Showing it always works.** A branched flow still has a knowable set of mails
and knowable gaps between them. The list shows them and marks the ones only some
readers get as `conditional`, with `condition` naming the fork. It is incomplete
as a structural picture and correct as what it claims to be.

**Editing is bound to the flow being a straight line**, because inserting,
reordering and deleting from a list only work if the mapping back onto the graph
is unambiguous.

### The rule (`Sequence\LinearityRule`)

An automation is **linear enough to edit from the list** when all seven hold:

1. It has exactly one trigger node.
2. No node has more than one outgoing edge.
3. No node has more than one incoming edge.
4. Every edge leaves its node through the `default` output.
5. No node is a Branch, Switch, Loop or Parallel node.
6. Every node is reachable from the trigger by following outgoing edges.
7. The chain contains no cycle.

Rules 4 and 5 overlap on purpose. Rule 4 already excludes a fully wired Branch;
rule 5 also excludes one with a single output wired, which passes 2, 3 and 4
while still being a branch — its other output can be connected from the canvas at
any moment, and a list that had rewritten the graph in the meantime would have
moved a node the branch was about to point at.

`filter` and `stop` are deliberately **not** on the branching list. Both have one
output and end the flow for the people they do not pass, which narrows who
continues without changing the order of anything. They make the mails after them
`conditional`; they do not lock the list.

Where the rule does not hold, the three write endpoints answer **422** carrying
the rule's own reasons, and the canvas stays the editing surface. Erring towards
"locked when it need not have been" is the right direction to err: the cost is a
trip to another screen, where the other direction's cost is a rewritten graph
nobody asked for.

### The gap belongs to the mail after it

Every `delay` is measured **from the previous mail**, never from the start. That
is what makes reordering lossless: "5 days after the previous mail" travels with
the mail when it moves, where "day 7" would silently misdescribe every row below
the one that moved.

Mechanically, a **step** is everything between one mail and the one before it —
any delay nodes, plus anything else in the gap (a tag, a CRM write) — and the
mail itself. Moving a mail moves its whole step. Anything in the step that is not
a delay is listed on the row as `also_runs`, so a reorder is never a silent
rewrite of what the flow does.

Deleting a mail deletes the delay nodes that preceded it — they were its gap and
mean nothing without it — and **keeps** everything else in the gap, handing it to
the following step.

### Which nodes are mails

This addon never learns what a newsletter is. A node declares itself:

```php
public static function mailStep(): bool
{
    return true;
}

/** @return array{label: string, reference: string|null} */
public static function mailSummary(array $config): array
{
    return ['label' => $config['subject'] ?? '', 'reference' => $config['campaign'] ?? null];
}
```

Both are optional and both are found with `method_exists`, so nothing existing
breaks. `goldnead/statamic-marketing` opts its `marketing.send_email` node in
from its own side. An install can also name handles in config:

```php
'sequence' => [
    'mail_nodes' => ['my_app.send_receipt'],
],
```

### The screen

The builder page carries a **Flow / Mails** switch. Same automation, same page,
read either way — a view rather than a second screen, because two screens over
one object is how the two start disagreeing.

The list shows every mail with the gap in front of it, badges a `conditional`
mail and names the fork underneath it, and lists whatever else runs in the same
gap. Where `editable` is false it does not say "not linear": it says which of
the seven conditions is broken, in words, with what to do about it and the
rule's own sentence naming the node. The mapping from the rule's prose back onto
the numbered conditions is in `resources/js/support/mailList.js`.

Reordering is a pair of buttons per row rather than a drag handle — reachable
from a keyboard, audible to a screen reader, and the list is short by nature.

Editing is refused, with its own message, in three cases: the flow is not a
straight line, the user lacks `edit automations`, or the canvas has unsaved
changes. The third exists because `ChainEditor` writes to the stored automation
directly: reordering under unsaved node edits would let the next canvas save put
the old order back. After every list write the page re-reads the stored graph,
so the canvas never keeps showing an order the server has replaced.

Which node types the "add a mail" form may offer comes from the page prop
`mailTypes`, built with `MailSteps::isMailHandle()`. It has to come from the
registry: an automation that sends nothing yet has no row to read the answer
off, and it is the one that most needs to add a mail.

## Mail rules — one trigger, one mail

The other end of the same idea. A sequence is many mails in order; a **rule** is
one: "when a form is submitted, send the thank-you mail to the sender." That
sentence fits on a row, and a row is a better editing surface for it than a
canvas with two boxes on it.

```
GET   /cp/automations/api/automations/{automation}/rule
PATCH /cp/automations/api/automations/{automation}/rule
```

The screen is **Tools → Automations → Mail rules**. It lists every automation
with exactly one mail node. One that sends nothing is not a rule; one that sends
three is a sequence, and the mail list above is its screen.

`GET` answers the sentence plus what somebody deciding whether it works needs
beside it:

```json
{
  "handle": "contact-reply",
  "trigger": { "handle": "form_submitted", "label": "Form submitted" },
  "mail": { "label": "Thanks for writing", "reference": "thank-you" },
  "recipient": "{{ form.email }}",
  "template": "thank-you",
  "enabled": true,
  "dispatch_mode": "async",
  "editable": true,
  "reasons": [],
  "recent_runs": [{ "id": 91, "status": "success", "finished_at": "…" }]
}
```

`PATCH` writes back `recipient`, `template`, `enabled` and `dispatch_mode`.
Only the keys you send are touched — a status toggle on one row must not
overwrite a template somebody else just picked.

### What it deliberately cannot do

**Create.** The screen edits automations that already exist, like the mail list.
Making one from a row would mean choosing a trigger, a node type and a handle,
and a row is the wrong surface for three decisions at once. Build it on the
canvas; it appears here as soon as it is a trigger, a mail and one edge.

**Edit a shape that is not a rule.** `Sequence\RuleShape` decides, and it builds
on `LinearityRule` rather than repeating it: every reason a mail list cannot be
edited is a reason a rule cannot be either. A delay between the trigger and the
mail, a second mail, a branch — the row is still shown, with the reason on it
and a link to the canvas. Showing it matters: "which mail goes out when the
contact form is submitted" is a question that deserves an answer whether or not
the flow behind it grew a delay.

**Write a field the mail node does not have.** The recipient is `to` and the
template is `template`, but both are looked up in the node's own schema first
(`Sequence\RuleFields`). A mail node that takes its recipients from a mailing
list has no `to`; writing one anyway would leave a key in its config that
nothing reads — an edit that looks applied and does nothing. Read side and write
side use the same lookup, so a row can never show one field and write another.

### The sync switch on the row

`dispatch_mode` is the per-trigger switch from 1.10. On the row it says what it
actually costs: **the request waits for the whole run.** It does not say that
errors reach the caller, because they do not — `WorkflowRunner` writes
`STATUS_FAILED` on the run and returns normally, queued or not. The price is
time, not error handling.

### Where `statamic-notifications` fits

Nowhere, on purpose. It carries a nav item pointing here when this addon is
installed and its rules screen exists (`Support\AutomationRules`), and no send
path of its own. If both addons could turn an event into a mail, "why did this
mail go out" would have two possible answers and no way to tell them apart.

## Re-entry (`Support\RestartPolicy`)

What happens when somebody enters an automation they have already entered.
Configured on the trigger node — the field is on **every** trigger, including
third-party ones, because the registry appends it rather than each class
declaring it.

| Value | Meaning |
|---|---|
| `always` (default) | Enroll again every time, in parallel with whatever is running. Unchanged behaviour. |
| `ignore` | If this contact has ever been in this automation, do nothing. What a welcome series wants. |
| `restart` | Cancel the open pass — **and its scheduled job** — and start fresh from the trigger. |
| `resume` | Leave an open pass exactly where it is; add nothing. Enroll normally when there is none. |

The default is load-bearing. Every automation in every install is on `always`,
and an unrecognised value reads as `always` too, so a typo in an imported file
cannot start suppressing enrollments.

Three of the four need to know who a run is about. That is `subject_key` on the
run, taken from the trigger's *Contact identified by* field, or from
`subscriber.email` → `contact.email` → `lead.email` → `user.email` → `email`,
lower-cased and trimmed. A trigger that names nobody — a scheduled sweep, a
webhook with no address in it — falls back to `always` and logs it, because
treating every subjectless run as the same subject would make one nightly sweep
block every later one for ever.

## Enrollment and completion (`Support\RunStats`)

```php
app(RunStats::class)->forAutomation($automation->uuid);
// ['enrolled' => 412, 'in_progress' => 38, 'completed' => 351, 'exited' => 20, 'failed' => 3]
```

Read out of `automation_runs`, grouped by status, one query for the whole
listing. No new table: a run *is* an enrollment, and a second table recording the
same facts would be a second place for them to disagree.

- `in_progress` — `queued`, `running`, `waiting`
- `completed` — `success`
- `exited` — `stopped`, `cancelled`
- `failed` — `failed`

Test runs are excluded by default. `distinctSubjects()` answers how many *people*
that was, which differs from `enrolled` exactly as much as the re-entry policy
allows repeats.
