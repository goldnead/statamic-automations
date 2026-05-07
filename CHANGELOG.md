# Changelog

All notable changes to **Statamic Automations** are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
