# Storage drivers

Automation **definitions** (the graph: nodes + edges, plus name/handle/enabled)
can live in one of two places, chosen with `automations.storage.driver`:

| Driver | Definitions live in | Best for |
| --- | --- | --- |
| `database` (default) | Eloquent rows | Dynamic, CP-driven editing on one environment |
| `flat_file` | One YAML file per automation | Git-tracked, deploy-as-code workflows |

```php
// config/automations.php
'storage' => [
    'driver' => env('STATAMIC_AUTOMATIONS_STORAGE', 'database'), // database | flat_file
    'flat_file' => [
        'path' => env('STATAMIC_AUTOMATIONS_DEFINITIONS_PATH', null), // default resources/automations
    ],
],
```

Both drivers are accessed through the same `AutomationRepository` contract, so
the engine, CP screens, triggers and sub-automation calls behave identically
regardless of where definitions are stored.

## What always lives in the database

Only **definitions** move to flat files. **Runtime data** is always in the
database, because it is high-volume, append-heavy and queried/paginated:

- `automation_runs`, `automation_node_runs`
- `automation_scheduled_jobs` (delays / waits)
- `automation_audit_logs`

In flat-file mode, runs reference their definition by `automation_uuid` (there
is no foreign-key row to point at), and the run listing/dashboard resolve names
through the repository. **Versioning** uses Statamic Revisions (flat-file YAML)
in both modes — see below.

## `flat_file` driver vs. `automations:sync`

These solve different problems and can be used independently:

- **`storage.driver = flat_file`** — the YAML files *are* the runtime source of
  truth. The engine reads definitions straight from them; there is no database
  copy of the graph.
- **`automations:sync`** — a mirror/export tool for the **database** driver. The
  DB stays authoritative; files are a portable copy for Git or migration
  (see [file-sync.md](file-sync.md)).

If you adopt the flat-file driver you generally don't need `automations:sync`:
commit the YAML files and deploy.

## Versioning (Statamic Revisions)

Every save snapshots the graph as a Statamic **Revision** (flat-file YAML under
the revisions store, keyed `automation::<uuid>`), so automation history sits
alongside content revisions and is portable with the rest of the site. Reverting
restores a revision by timestamp and is itself snapshotted first. Cap retained
revisions with `automations.versioning.keep`.

## Editing in flat-file mode

The CP builder still works in flat-file mode — saving writes the YAML file. For
pure deploy-as-code setups, edit the YAML in your editor and let your deploy put
the files in place; no database step is required.
