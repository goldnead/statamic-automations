# Design — Automations Editor: Functional Overhaul

> Epic: `automations-editor-functional-overhaul`
> Addon: `statamic-automations` (branch `main`)
> Datum: 2026-07-19
> Verwandt: `DESIGN-PLAN-editor-overhaul.md` (kosmetische UI-Politur — separates Follow-up, NICHT Teil dieses Epics), Handoff `GoldnerOS/TASKS/statamic-addons-handoff-2026-07-19.md`.

## 1. Problem

Der Node-/Canvas-Editor sieht mächtig aus (viele Trigger-/Logic-/Action-Nodes), aber funktional ist vieles kaputt oder gar nicht verdrahtet. Adrians Feedback (2026-07-19):

- Loops loopen nicht, Switch switcht nicht.
- Config-Felder sind größtenteils Textfelder ohne klare Semantik — sie müssten dynamisch an die passenden Statamic-Entitäten gebunden sein.
- Wo eine Auswahl möglich wäre (Entries, Webhooks, Collections, Forms), wird nichts angezeigt (leere Dropdowns).
- Statamic-Events sollten breiter abgedeckt sein.
- UI: rechte Detail-Sidebar soll ausblenden, wenn kein Node gewählt ist; linke Node-Palette soll Tabs statt einklappbarer Listen sein.

Ziel: **jeder Node tut verlässlich, was draufsteht, und ist über echte Picker konfigurierbar** — nicht mehr Kosmetik, sondern Funktion und Verdrahtung.

## 2. Root Causes (verifiziert im Code)

| Symptom | Ursache | Ort |
|---|---|---|
| Switch switcht nicht | Editor erzeugt für Switch nur einen `default`-Output → Case-Ausgänge sind gar nicht verbindbar. Engine liefert den Case-Handle korrekt, aber es existiert keine Kante mit `from_output === caseHandle`, also stoppt `nextNode()` den Walk. | `resources/js/composables/useAutoLayout.js::outputsFor()` (~Z.39, `branchTypes` hardcoded), `Canvas.vue` `HANDLE_FRACTION` (~Z.115 kennt nur default/true/false), `src/Engine/WorkflowRunner.php::nextNode()` |
| Loop loopt nicht | `LoopNode::execute()` iteriert NICHT die nachfolgenden Graph-Nodes, sondern verlangt eine separate Ziel-Automation (`config['automation']`, required) und läuft die pro Item. Nodes hinter dem Loop tun nichts. | `src/Nodes/.../LoopNode.php:85` |
| Leere Dropdowns | Backend liefert `options_source`-Optionen (`NodesController::options()`, Route `options/{source}`), aber die Vue-`ConfigPanel` ruft sie NIE ab. `grep options_source resources/js` = leer. `fieldProps()` verdrahtet nur statische `field.options`. | `resources/js/components/builder/ConfigPanel.vue::fieldProps()` (~Z.200), `src/Http/Controllers/NodesController.php::options()`, `routes/cp.php:108` |
| Events zu schmal | Trigger-Event-Map deckt nur eine Handvoll ab (EntrySaved/Deleted, UserRegistered, FormSubmitted, EntryPublished). | `src/ServiceProvider.php::registerEventListeners()` (~Z.367-416), `src/Engine/TriggerDispatcher.php::dispatch()` |
| Sidebar/Palette-UX | Rechte `ConfigPanel` immer sichtbar (fester Grid-Slot `grid-cols-[260px_1fr_360px]`); linke `NodeLibrary` nutzt Accordion (`open`/`toggle()`). | `resources/js/pages/Automations/Edit.vue`, `resources/js/components/builder/NodeLibrary.vue` |

## 3. Scope

**In scope:** Runtime-Korrektheit aller Control-Flow-Nodes, dynamische Config-Verdrahtung aller Nodes (Full + Token-Insertion), breitere Statamic-Event-Coverage, Editor-UX (Sidebar-Auto-Hide, Palette-Tabs), Abschluss: welcome-series umbauen (Handoff-Punkt A).

**Out of scope (eigene Tickets):** kosmetischer UI-Overhaul (`DESIGN-PLAN-editor-overhaul.md`), leadhub Template-MVP-Leiche (Handoff C), Addon-CI-Auth für private Deps (Handoff D), Undo/Redo-Rebuild, Templates-Tab, Performance-Bars.

**Leitprinzipien:** Rein additiv und reversibel — keine bestehende Automation zerstört. TDD für die Engine (kaputtes Verhalten zuerst per Test reproduzieren, dann fixen). Keine neuen UI-Libs (bestehendes Vue-Flow + `@statamic/cms/ui`). Alle Änderungen zuerst auf Testbench, dann gegen echte Staging-Site (Kollisions-/Realitätscheck), dann Deploy über Tag-+-Worktree-Weg.

## 4. Node-Audit (Rückgrat des Epics)

Jeder Node wird durchgegangen: (a) Runtime-Verifikation, (b) Config-Verdrahtungs-Pass. Der Audit ist Deliverable von Phase 1/2 und deckt still nichts-tuende Nodes auf.

### Trigger (`src/Nodes/Triggers/` + Integrations)
| Handle | Runtime | Config-Verdrahtung |
|---|---|---|
| `manual` | ok | ggf. keine |
| `form_submitted` | verifizieren via `HandleFormSubmitted` | **Form-Picker** (`statamic.forms`) — heute leer |
| `entry_published` | verifizieren via `HandleEntryPublished` | **Collection-Picker** — heute leer |
| `entry_saved` | verifizieren (generische Map) | Collection-Picker |
| `entry_deleted` | verifizieren | Collection-Picker |
| `user_registered` | verifizieren | optionaler Role-Filter |
| `scheduled` | Cron-Ausführung verifizieren | Schedule-Config klar machen |
| `webhook_received` | verifizieren (optional registriert) | Webhook-Config |
| LeadHub/Marketing-Trigger | verifizieren (`src/Integrations/`) | pro Trigger prüfen |

### Logic (`src/Nodes/Logic/` + `Nodes/Actions/`)
| Handle | Runtime | Aufgabe |
|---|---|---|
| `filter` | ConditionBuilder verifizieren | ok, Token-Insertion in Conditions |
| `branch` | true/false ok | Referenz-Implementierung für dyn. Outputs |
| `switch` | **KAPUTT** | Phase 1: dynamische Case-Outputs + `default` |
| `stop` | ok | — |
| `delay` | **verifizieren** (Queue/Delay real?) | Zeit-Config typisieren |
| `wait_until` | **verifizieren** | Bedingung + Timeout |
| `loop` | **KAPUTT** | Phase 1: Inline-Subgraph |
| `parallel` | **verifizieren** (dyn. Outputs?) | mehrere Zweige echt |
| `throttle` | **verifizieren** (Stub-Verdacht) | Rate-Config |
| `set_variable` | verifizieren | Wert-Feld + Token-Insertion |
| `call_automation` | verifizieren | **Automation-Picker** — heute leer |

### Action (`src/Nodes/Actions/`)
| Handle | Runtime | Aufgabe |
|---|---|---|
| `send_email` | ok | **et_templates-Picker** + Recipients + Token-Insertion |
| `send_webhook` | verifizieren | URL/Method/Body + **Webhook-Picker** (webhook-manager) |
| `add_log_entry` | ok | Text + Token-Insertion |
| `create_entry` | verifizieren | **Collection-Picker** + Blueprint-Feld-Mapping |
| `update_entry` | verifizieren | **Entry-Picker** (kaskadierend) + Feld-Mapping |
| `create_user` | verifizieren | **Role-Picker** + Felder |
| `ai_generate` | verifizieren | Prompt (Token-Insertion) + Model-Config |

## 5. Phasen

### Phase 1 — Runtime-Korrektheit aller Control-Flow-Nodes (Backend)
- **Loop → Inline-Subgraph:** `LoopNode` bekommt zwei Ausgänge, `loop` (Schleifenkörper) und `done`. `WorkflowRunner::walk()` lernt: bei Loop-Node `items`-Array auflösen (Token), pro Item Loop-Kontext (`{{item}}`, `{{index}}`, `{{loop.count}}`, `{{loop.first}}`, `{{loop.last}}`) in den Run-Scope setzen und den vom `loop`-Ausgang erreichbaren Subgraph bis zum natürlichen Ende ausführen (Subgraph endet, wo keine ausgehende Kante mehr existiert). Nach allen Items weiter auf `done`. Separate-Automation bleibt optionaler Advanced-Modus (`config.mode = inline|automation`, default `inline`; Pflicht-Constraint auf `automation` entfällt).
- **Switch → echte Case-Ausgänge:** Editor erzeugt pro `config.cases[]` einen Output-Handle + einen `default`-Fallback. `useAutoLayout.outputsFor()` + `Canvas.HANDLE_FRACTION` positionieren **N Handles dynamisch**. Engine liefert den Case-Handle bereits — mit den neuen Kanten routet Switch. `default` greift, wenn kein Case matcht.
- **Dynamische Outputs generisch:** Dieselbe „N-Output"-Mechanik trägt auch `parallel` (mehrere Zweige) und bleibt kompatibel zu `branch` (true/false).
- **Restliche Logic-Nodes verifizieren:** `delay`, `wait_until`, `throttle`, `parallel`, `set_variable`, `call_automation` — Runtime durchgehen, Stubs/Bugs fixen.
- **TDD (pest):** Reproduktions-Tests für Loop-Iteration, Switch-Routing, Loop-in-Switch, Parallel-Fan-out; dann grün.

### Phase 2 — Config-Felder dynamisch verdrahtet (alle Nodes, Full + Token-Insertion)
- **Async-Options in `ConfigPanel.vue`:** Felder mit `options_source` fetchen künftig `NodesController::options({source})` (existiert, wird nie aufgerufen — Ursache der leeren Dropdowns). Cache pro Source + Loading-/Error-State.
- **Parametrisierte + kaskadierende Quellen:** Backend `options()` erweitert um `entries?collection=…`, `terms?taxonomy=…`, `webhooks` (aus webhook-manager, falls installiert; sonst leer + Hinweis), `automations`, `users`, `roles`, `blueprints?collection=…`, `assets`, `sites`, `globals`. Entry-Feld = kaskadierend: `collection`-Feld wählt die Quelle des `entry`-Feldes (Abhängigkeit über `depends_on` im Schema).
- **Token-Insertion:** kleiner `{{ }}`-Inserter an Text-/Textarea-Feldern, der die an diesem Node **verfügbaren Variablen** listet. Verfügbarkeit = Edge-Walk upstream + `outputSchema()` jedes Upstream-Nodes. Fehlende Action-`outputSchema()` werden ergänzt.
- **Feldtypen:** `key_value`, `condition_list`, `integer`, `data_reference` bekommen echte Fieldtypes statt Textarea/Input.
- **Schema-Erweiterung:** Node-`schema()` unterstützt `options_source` (+ optionale `source_params`/`depends_on`) und `tokenable: true`. Audit-Tabelle (Abschnitt 4) wird pro Node abgearbeitet.

### Phase 3 — Breitere Statamic-Event-Coverage (Trigger)
- Event-Map in `ServiceProvider::registerEventListeners()` + Trigger-Klassen erweitern auf: Entry Created/Saving/Saved/Published/Unpublished/Deleted, Term Saved/Deleted, User Saved/Registered/Deleted, Form Submitted / Submission Created, Asset Uploaded/Saved/Deleted, Global Saved, Nav Saved.
- Jeder neue Trigger: `matches()`-Filter (z.B. Collection-/Taxonomy-Constraint), `buildContext()`, `outputSchema()` (für Token-Insertion aus Phase 2).
- Über `TriggerDispatcher::dispatch()` laufen lassen (bestehendes Muster), Doppel-Registrierung vermeiden.

### Phase 4 — Editor-UX (Vue)
- **Rechte Detail-Sidebar:** blendet komplett aus, wenn kein Node gewählt ist (Grid-Slot `360px` → `0`, Canvas gewinnt Platz); öffnet automatisch bei Node-Auswahl, schließbar per X. Kein leerer „Select a node"-Slot mehr, der Platz frisst.
- **Linke Palette → Tabs:** `Triggers | Logic | Actions` als Tabs statt Accordion (`NodeLibrary.vue`), eine Suche filtert über alle Tabs. Ersetzt die einklappbaren Gruppen.
- Sauberes Unmount bei Route-Wechsel beachten (Single-Root/`isolate`-Bugfix nicht regressen).

### Phase 5 — welcome-series umbauen (Handoff-Punkt A)
- Gegen die **echte Staging-Automation** `welcome-series`, reversibel: jede Mail als gebrandeter `et_templates`-Entry anlegen; Nodes von „Send Branded Email" (Host-Action) auf „Send Email Notification" (Addon-`SendEmailAction`, et_templates-Picker, gebrandet) umverdrahten.
- Datenmodell: `automation_nodes` + `automation_edges` (`from_node_key`/`from_output`/`to_node_key`) ist die Wahrheit. Original-Nodes nicht blind löschen — reversibel, danach im Browser verifizieren.
- Reihenfolge/Timings: bestehende Node-Reihenfolge übernehmen, sofern Adrian nichts anderes vorgibt.

## 6. Datenmodell & Kern-Interfaces (unverändert, nur erweitert)
- Wahrheit: Tabellen `automation_nodes`, `automation_edges` (`from_node_key`, `from_output ∈ {default,true,false,<case>,loop,done,…}`, `to_node_key`).
- Contracts: `AutomationNode` (static `handle/label/schema`), `AutomationTrigger` (`matches/buildContext/outputSchema`), `AutomationAction` (`execute`). Logic-Nodes duck-typed (`evaluate()`/`execute()`) — bleibt, wird aber pro Node verifiziert.
- Registry: `NodeRegistry` (+ Legacy `TriggerRegistry`/`ActionRegistry`), Registrierung in `ServiceProvider::registerBuiltInNodes()`.

## 7. Verifikation
- **Schnell-Loop:** Testbench `http://statamic-addon-testbench.test/cp` (`info@adriangoldner.com` / `password`): `npm run build` → `php artisan vendor:publish --force` → Browser-Check.
- **Realität:** echte Staging-Site (`staging.adriangoldner.com/cp`) für Kollisions-/Realcheck (Testbench zeigt z.B. `et_templates`-Kollision nicht).
- **Pro Phase:** Engine-Tests grün (Phase 1); jeder Picker zeigt Optionen + kaskadiert (Phase 2); jeder neue Trigger feuert (Phase 3); Sidebar-Auto-Hide + Tabs in Light/Dark (Phase 4); welcome-series läuft end-to-end mit gebrandeter Mail (Phase 5).
- **Deploy:** Tag-+-Worktree-Weg aus dem Handoff (Merge allein reicht nicht — neuer Tag `vX.Y.Z`, dann Staging-Bump im isolierten Worktree). Adrians uncommittete adriangoldner.com-WIP NIE anfassen.

## 8. Risiken
- **Inline-Loop-Subgraph-Ende:** „Wo endet der Schleifenkörper?" — Definition: Body läuft bis zu Nodes ohne ausgehende Kante. Verschachtelte Loops/Switches im Body müssen korrekt scopen → Tests dafür.
- **Dynamische Handle-Positionen:** N Outputs (Switch/Parallel) verschieben Handle-Y-Positionen → Edge-Anschlüsse nach Umbau visuell prüfen.
- **webhook-manager optional:** `webhooks`-Source leer + Hinweis, wenn Addon nicht installiert (kein Fehler).
- **Token-Insertion-Verfügbarkeit:** Upstream-Variablen nur korrekt, wenn `outputSchema()` gepflegt ist — fehlende ergänzen, sonst zeigt der Inserter zu wenig.
- **welcome-series greift in echte Automation:** reversibel arbeiten, Original-Nodes behalten bis verifiziert.
- **Vue-Flow-CSS unscoped:** UI-Änderungen über Computed-Styles diffen, nicht nur Screenshots (CLAUDE.md-Regel).
- **CI vorbestehend rot** (private Deps): blockiert nicht (Admin-Merge-Weg), aber beim Taggen bewusst.
