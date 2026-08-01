# Statamic Marketplace Copy

Source material for the Statamic Marketplace listing.

---

## Title

**Statamic Automations**

## Tagline / Short Description

A visual automation builder for Statamic: turn form submissions, entries, leads and webhooks into multi-step workflows, right inside your Control Panel.

## Long Description

Statamic Automations is a visual automation layer built specifically for Statamic websites. Instead of wiring up bespoke event listeners and queue jobs for every "when X happens, do Y" requirement, you build the flow visually: pick a trigger, drop in conditions and actions, connect the nodes, and enable it.

It runs natively inside the Statamic 6 Control Panel (Inertia + Vue 3 + the native `@statamic/cms` UI). Every run is logged node-by-node, so you can see exactly what fired, what each step received, and retry from any failed node. Workflows can be exported to JSON and synced to flat files, so they live in version control alongside the rest of your site.

It is not a heavyweight external automation SaaS. It is the missing "if this, then that" layer for the content and forms you already have in Statamic.

## Positioning Sentence

You shouldn't need Zapier and a glue server to email a team member when a form comes in. Statamic Automations keeps your workflow logic in Statamic, versioned and visible.

## Key Features

- Visual flow builder on a Vue Flow canvas, inside the CP
- Triggers: form submitted, entry published/saved, manual run
- Logic nodes: filter, branch, delay, stop
- Actions: send email, send webhook, add log entry
- Token interpolation (`{{ form.email }}`) across node config
- Per-run logging with node-by-node status and retry-from-node
- Built-in template catalog to install common flows in one click
- Export to JSON + flat-file sync for version control
- Test mode: dry-run a flow with sample context before enabling
- Granular CP permissions
- First-class integrations with LeadHub and Webhook Manager when installed
- Extensible: register your own triggers, actions and logic nodes

## Who It's For

- Statamic agencies and freelancers building client sites
- Teams that want form/entry automations without an external SaaS
- Anyone already using LeadHub or Webhook Manager who wants to orchestrate them

## Who It's *Not* For

- Full marketing-automation suites (drip campaigns, audience segmentation)
- General-purpose iPaaS replacing Zapier/Make across non-Statamic systems

## Categories

Workflow · Forms · Integrations · Utility · Developer Tools

## Requirements

- PHP 8.2+
- Statamic 6
- Laravel 11, 12 or 13

## Suggested Pricing Tiers

| Tier | Price | Includes |
|---|---|---|
| **Automations Core** | $79–129 | Visual builder, built-in triggers/logic/actions, runs + retry, templates, export/sync |
| **Automations Pro** | +$50 | Custom triggers/actions, advanced nodes, remote license features |
| **Integration Bundles** | $29 each | LeadHub pack, Webhook Manager pack |
