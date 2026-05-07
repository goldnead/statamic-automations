# Autosave

The Automation Builder includes an opt-in autosave that writes changes
back to the API 2 seconds after the user stops typing or moving nodes.

## Toggle

A checkbox in the Builder topbar enables or disables autosave for the
session. The setting is intentionally not persisted — autosave should
be a deliberate choice per editing session, not a silent global.

When enabled, an indicator next to the checkbox shows the current
state:

| State | Meaning |
|---|---|
| _Autosave off_ | the toggle is off; nothing scheduled |
| _Saving…_ | a save is queued or in flight |
| _Saved at HH:MM:SS_ | last save succeeded |
| _Autosave failed_ | last save errored — hover for the message |

## When it triggers

Autosave watches:

- `automation.name`
- `automation.description`
- the entire `nodes` array (additions, removals, position changes, config edits)
- the entire `edges` array

It does **not** trigger on:

- toggling the Enabled state (this goes through a separate validated endpoint)
- running tests
- exporting / importing JSON

## When it skips

- The first save of a brand-new automation must be manual (so the
  user can review the auto-generated handle before committing).
- Network errors are retried on the next change rather than looped.
- The composable cancels any pending timer when the component unmounts.

## Implementation note

Autosave is implemented as a Vue composable in
`resources/js/composables/useAutosave.js`. It exposes `status`,
`lastSavedAt`, `lastError`, `enabled`, `flush()` and `toggle()` so
custom builders can swap in their own indicators.
