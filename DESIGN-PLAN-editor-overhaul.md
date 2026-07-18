# Design Plan — Automations Editor UI Overhaul

> Ziel: Der Node-/Canvas-Editor soll im Statamic-6-CP **native** wirken. Layout und
> Informationsarchitektur werden aus den Inspiration-Screenshots übernommen, aber
> **alle Farben/Buttons/Radii/Spacings laufen über Statamic-CP-Tokens** — kein
> Custom-Lila, keine Fremdprodukt-Optik.
>
> Dieser Plan ist so geschrieben, dass ihn ein Agent **mit Browser-Feedback**
> Schritt für Schritt abarbeiten kann. Ohne Browser wird hier bewusst nichts
> blind umgebaut.
>
> Verwandte Tickets: `STATE/backlog/backlog-statamic-automations-editor-native-ui`,
> Epic `STATE/backlog/backlog-statamic-addons-native-design-epic.md`
> (Abschnitt „Automations-Editor — Ziel-Design").
> Referenz-Screenshots: `STATE/backlog/assets/statamic-automations-inspiration-5..10.png`.
> (Screenshot 10 „Flowwbit" ist am nächsten an einer neutralen, tokenisierbaren Zielästhetik.)

---

## 1. Ist-Analyse (Stand heute)

### 1.1 Komponenten & Dateien

| Rolle | Datei | Aktueller Zustand |
|---|---|---|
| Editor-Seite / Top-Bar / Grid-Shell | `resources/js/pages/Automations/Edit.vue` | 3-Spalten-Grid `grid-cols-[260px_1fr_360px]`, `Header` mit Name-Input + Validate/Test/Export/Enabled-Switch/Save; `Alert` für Validation-Issues; Run-Log als `Stack`. |
| Canvas | `resources/js/components/builder/Canvas.vue` | `@vue-flow/core` `VueFlow` + `Background` (Dots, gap 16, `#aaa`), `Controls`, `MiniMap`; Node-Templates `trigger`/`action`/`logic` → `NodeCard`; Edges `smoothstep`, true=grün/false=rot. |
| Node-Card | `resources/js/components/builder/NodeCard.vue` | `.sa-node` mit Badge (Kind), Titel, `type` (mono), optionalem Summary; `Handle` links (target) / rechts (source); Branch-Handles true/false. Kein Icon-Chip, kein Kontext-Menü, keine Variablen-Chips. |
| Node-Library (links) | `resources/js/components/builder/NodeLibrary.vue` | Überschrift + `Input`-Suche + Gruppen Triggers/Logic/Actions als `<li>`-Cards (Label + Description). Kein Icon je Node, keine Count-Badges, keine Tabs. |
| Properties-Panel (rechts) | `resources/js/components/builder/ConfigPanel.vue` | Dynamisches `Field`-Formular aus `schema`, `ConditionBuilder` bei Conditions. Flach, keine Sektionen, keine Collapse, keine Meta-/Runtime-/Performance-Blöcke, keine Primary-Action unten. |
| Bedingungen | `resources/js/components/builder/ConditionBuilder.vue` | (bestehend, hier nur einbetten, nicht neu bauen). |
| Run-Log | `resources/js/components/builder/RunLogPanel.vue` | (bestehend, im `Stack`). |

### 1.2 Aktuelle Styling-Quellen

- **`resources/css/cp.css`** — Tailwind v4 (`@import "tailwindcss"`), Layer-Ordnung an Statamic angelehnt (`addon-theme` → `addon-utilities`).
  - `--sa-color-{trigger,action,logic,success,failed,…}` als CSS-Vars — **ziehen bereits aus Statamic-Theme-Tokens** (`--color-blue-500`, `--color-emerald-500`, `--color-amber-500`) mit Fallback. Das ist gut: beibehalten, nirgends Custom-Hex ergänzen.
  - `.sa-node*` Klassen: `rounded-md`, `border`, `bg-white dark:bg-gray-800`, `shadow-sm`, `min-w-[180px]`. Farb-Akzent nur als 3px `border-left`.
- **Vue-Flow-Basisstyles** werden in `Canvas.vue` via `<style>` importiert (`@vue-flow/core/dist/{style,theme-default}.css`, controls/minimap). Diese bringen eigene Farben/Radii mit → müssen mit CP-Tokens überschrieben werden.
- **Statamic-Tokens/Komponenten** (Quelle der Wahrheit): `@statamic/cms/ui` (`Button`, `Badge`, `Panel`, `Card`, `Icon`, `Field`, `Input`, `Switch`, `Select`, `Dropdown`, `DropdownItem`, `Header`), Tailwind-Utilities (`bg-body-bg`, `border-content-border`, `text-gray-*`, `bg-gray-*`), `--theme-color-content-bg`. `dark:`-Variante über `.dark`-Klasse (bereits im `cp.css` als `@custom-variant dark` repliziert).

### 1.3 Kernbefund

Funktional vollständig, aber visuell „selbstgebaut": flache Node-Cards ohne Icon-Chip/Meta/Menü, Library ohne Icons/Counts, Properties-Panel ohne die im Ziel geforderte Sektions-/Collapse-Struktur, Top-Bar ohne Breadcrumb/Status-Pill/Undo-Redo, Canvas-Controls in Vue-Flow-Default-Optik. **Keine** Custom-Lila-Sünde im Code (die `--sa-color-*` sind bereits Statamic-Tokens) — der Overhaul ist damit primär **Struktur + Token-Anwendung**, nicht Entfärbung.

---

## 2. Soll-Design (aus Inspiration abgeleitet, auf Statamic-Tokens gezogen)

Gemeinsame Muster der Screenshots 5–10, übersetzt in CP-native Bausteine:

### 2.1 Node-Card (neue Sub-Komponente `NodeCard.vue`, erweitert)

- **Kopfzeile:** farbiger **Icon-Chip** oben links (gerundetes Quadrat `rounded-lg size-8`, Hintergrund = Kind-Akzent in schwacher Deckung, Icon = `@statamic/cms/ui` `Icon`), **fetter Titel** + **grauer Subtitle** (Node-Handle/`type`), rechts ein **Meta-Badge** (`Badge`, z.B. Kind „Trigger/Action/Logic" oder „DATA") und ein **`...`-Kontextmenü** (`Dropdown`/`DropdownItem`: Rename, Duplicate, Disable, Delete).
- **Body (optional):** ein bis zwei **Config-Zusammenfassungszeilen** mit kleinem Label (z.B. „Send email → …", „POST <url>") und **Variablen als Chips** (`{{service_name}}` → kleines `rounded` Pill, `bg-gray-100 dark:bg-gray-800`, mono).
- **Status-Row:** dezentes Kind-Label / Status-Punkt unten (grün=ok, amber=incomplete, rot=invalid) — Farben aus `--sa-color-*`.
- **Selected-State:** Ring in Statamic-Blau (`ring-2 ring-blue-500/60`) statt Border-Farbwechsel.
- **Card-Grundform:** `rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm`. Akzent weiterhin als linker Rand ODER als Icon-Chip-Farbe (eins von beiden, nicht doppelt).
- **Handles:** target links / source rechts; Branch (`branch`) → true/false rechts, Labels als **Pills** (siehe 2.2). Handle-Dots in `--sa-color-*` bzw. `gray-400`.

### 2.2 Connectoren / Edges (in `Canvas.vue`)

- Glatte `smoothstep`-Linien (bestehend), Verbindungspunkte als **Dots** an den Handles.
- **Branch-Labels als Pills** („If true"/„If false", „Condition 1/2"): weiße/gray Pill mit dünnem Border, Textfarbe grün/rot bei true/false — via Vue-Flow `edge-label` Slot oder Edge `label` + Custom-Klasse `sa-edge-label`.
- Stroke-Default = `gray-400`/`gray-500`; true=`--sa-color-success`, false=`--sa-color-failed`.

### 2.3 Node-Library links (`NodeLibrary.vue`)

- **Kopf:** optionale Tabs (`Nodes` / ggf. `Templates`), darunter **eine** Suchleiste (`Input type=search`).
- **Gruppen** Triggers / Actions / Logic mit **Count-Badge** je Gruppe (`Badge` klein, `gray`), kollabierbar.
- **Je Eintrag:** **Icon** (Kind-Akzent-Chip) + **Name** + **Kurzbeschreibung** (`description`), Hover = `bg-gray-50 dark:bg-gray-800`, Border-Highlight `blue-400`. Drag-Handle optional (v2).
- Panel-Hintergrund = `bg-body-bg`/`--theme-color-content-bg`, rechte Trennlinie `border-content-border`.

### 2.4 Properties-Panel rechts (`ConfigPanel.vue`)

Umbau von flach → **kollabierbare Sektionen** (neue Sub-Komponente `PropertiesSection.vue`: Header mit Titel, `...`-Menü optional, Collapse-Chevron; Body). Reihenfolge:

1. **Detail information** — Name, Description (frei editierbar; mappt auf Node-Label/Beschreibung).
2. **Configuration / Input** — das bestehende dynamische `Field`-Formular (`fields` + `ConditionBuilder`). Das ist der funktionale Kern und bleibt inhaltlich unverändert, nur in eine Sektion gehüllt.
3. **Properties** (read-only) — Type, Status (Badge), Created, Updated, Version (aus `automation`/`node`-Meta, soweit vorhanden; fehlende Felder weglassen statt erfinden).
4. **Runtime Settings** — Auto-run on trigger, Timeout (ms), Retry attempts, Stop on error. **Nur** rendern, wenn das Node-/Automation-Schema diese Felder wirklich hat; sonst Sektion ausblenden (nichts erfinden — CLAUDE.md-Regel).
5. **Performance** — Success rate / Response time als **Bars** (Statamic-`--sa-color-success`), nur wenn Run-Daten existieren.

- Sektionen `border-b border-gray-200 dark:border-gray-800`, Header `text-2xs uppercase tracking-wider text-gray-500`.
- **Primary-Action unten** (sticky Footer im Panel): kontextabhängig (z.B. „Delete node" `Button variant=danger`), plus sekundär „Duplicate".
- Leerzustand (kein Node gewählt) bleibt: „Select a node to configure it." — zentriert, `text-gray-500`.

### 2.5 Top-Bar (`Edit.vue` Header-Bereich)

- **Breadcrumb / Flow-Name:** „Automations / <Name>" — Name inline editierbar (bestehend) ODER als Dropdown mit Rename. `Icon name="hammer"` behalten.
- **Status-Pill:** Draft / Aktiv als `Badge` (`amber`/`green`) direkt neben dem Namen (statt nur Enabled-Switch rechts).
- **Aktionen rechts:** Undo/Redo (Icon-Buttons, neu — s. Risiken), Validate, Test, Export (`ghost`), **Save/Publish primär** (`variant=primary`). Enabled-Switch bleibt, aber gruppiert.
- „Last saved …"-Hinweis (autosave ist vorhanden via `useAutosave`) als kleiner grauer Text.

### 2.6 Bottom-Canvas-Controls (`Canvas.vue`)

- Vue-Flow `Controls` durch eine **eigene, CP-getönte Control-Bar** ersetzen oder überschreiben: Zoom `-` / `%` / `+`, Fit-to-view, Pan/Hand-Toggle, Run/Play. Als schwebende Pill unten-mitte/-links (`rounded-xl border bg-white/90 dark:bg-gray-900/90 shadow`), Buttons = `@statamic/cms/ui` `Button variant=ghost` + `Icon`.
- **Dotted-Grid** bleibt (`Background`), aber Dot-Farbe an CP anpassen (`gray-300`/`gray-700` statt `#aaa`).
- MiniMap optional beibehalten, Rahmen/Farben tokenisieren.

---

## 3. Konkrete Umbau-Schritte (Reihenfolge = empfohlene PR-Sequenz)

Jeder Schritt ist einzeln baubar + im Browser verifizierbar. Nach JS/Vue-Änderungen:
`npm run build` → `php artisan vendor:publish --force` (Testbench) → Browser-Check unter
`http://statamic-addon-testbench.test/cp` (Login `info@adriangoldner.com` / `password`).

**Schritt 0 — Token-Fundament (`cp.css`).**
- Neue Utility-Klassen im `addon-utilities`-Layer: `.sa-card`, `.sa-icon-chip`, `.sa-chip` (Variablen-Pill), `.sa-edge-label`, `.sa-section-header`, `.sa-control-bar`.
- Alle Farben via `--sa-color-*` / Tailwind-Gray-Blue-Tokens. **Verbot:** kein neuer Hex-Wert außer als Fallback in `var(--token, #fallback)`.
- Vue-Flow-Overrides ergänzen (`.vue-flow__controls`, `.vue-flow__minimap`, `.vue-flow__edge-path`, `.vue-flow__handle`) → CP-Farben/Radii.
- Risiko: Vue-Flow-CSS liegt in einem eigenen `<style>` in `Canvas.vue` (unscoped). Overrides mit ausreichender Spezifität bzw. `@layer`-Reihenfolge platzieren.

**Schritt 1 — Node-Card (`NodeCard.vue`).**
- Icon-Chip + Titel/Subtitle + Meta-`Badge` + `Dropdown`-Kontextmenü.
- Icon-Mapping je Node-Handle: kleine Map handle→`Icon`-Name (Fallback generisch je Kind). Icons aus `@statamic/cms/ui`.
- Variablen-Chips: `config`-Werte, die `{{…}}` enthalten, als `.sa-chip` rendern.
- Selected-Ring statt Border-Farbwechsel; Status-Punkt unten.
- Kontextmenü-Events nach oben geben (`@rename`, `@duplicate`, `@disable`, `@delete`) → in `Canvas.vue`/`Edit.vue` verdrahten (Delete/Disable existieren teils schon über `remove-node`).

**Schritt 2 — Edges & Branch-Pills (`Canvas.vue`).**
- Edge-Label-Slot für Branch-Pills (`.sa-edge-label`), true/false-Farben.
- Dot-Grid-Farbe + Handle-Styling via cp.css.

**Schritt 3 — Node-Library (`NodeLibrary.vue`).**
- Icon je Eintrag (gleiches handle→Icon-Mapping wie Node-Card, zentral auslagern in `resources/js/composables/useNodeIcon.js`).
- Count-Badges je Gruppe, kollabierbare Gruppen (`ref` je Gruppe), Hover-States tokenisiert.
- Suchfeld bleibt (nur eins). Optional Tabs-Gerüst (Templates später).

**Schritt 4 — Properties-Panel (`ConfigPanel.vue` + neue `PropertiesSection.vue`).**
- `PropertiesSection.vue`: Props `title`, `collapsible`, `defaultOpen`; Slot Body; Header `.sa-section-header` + Chevron.
- Bestehendes `Field`-Formular in Sektion „Configuration" hüllen (Logik unverändert).
- Sektionen Detail/Properties/Runtime/Performance **nur** bei vorhandenen Daten (Guards, kein Erfinden).
- Sticky Footer mit Primary-/Danger-Action.

**Schritt 5 — Top-Bar (`Edit.vue`).**
- Breadcrumb + Status-`Badge` links, Aktionsgruppe rechts, Save/Publish primär.
- Undo/Redo nur, wenn ein History-Stack existiert (sonst als disabled Platzhalter oder in v2 verschieben — s. Risiken).
- „Last saved"-Text aus `useAutosave`-Zustand.

**Schritt 6 — Bottom-Control-Bar (`Canvas.vue`).**
- Eigene `.sa-control-bar` (Zoom/Fit/Pan/Run) via Vue-Flow `useVueFlow()`-API (`zoomIn`/`zoomOut`/`fitView`/`setInteractive`). Default-`Controls` ausblenden oder ersetzen.
- Achtung: Vue Flow ist jetzt per `:id="flowId"` gescoped (siehe Bugfix) — `useVueFlow(flowId)` mit derselben id verwenden, damit die Control-Bar dieselbe Instanz steuert.

**Schritt 7 — Politur & Dark-Mode-Durchgang.**
- Jede Sektion in Light + Dark prüfen, i18n-Strings vervollständigen (keine EN/DE-Mischung), Spacing an Statamic-Dashboard angleichen.

---

## 4. Neue/anzupassende Dateien (Übersicht)

| Datei | Aktion |
|---|---|
| `resources/css/cp.css` | Neue Utility-Klassen + Vue-Flow-Overrides (Schritt 0). |
| `resources/js/components/builder/NodeCard.vue` | Erweitern (Icon-Chip, Meta, Menü, Chips, Selected-Ring). |
| `resources/js/components/builder/NodeLibrary.vue` | Icons, Count-Badges, Collapse. |
| `resources/js/components/builder/ConfigPanel.vue` | In Sektionen umbauen, Footer-Action. |
| `resources/js/components/builder/PropertiesSection.vue` | **Neu** — kollabierbare Panel-Sektion. |
| `resources/js/components/builder/Canvas.vue` | Edge-Pills, Dot-Farbe, eigene Control-Bar. |
| `resources/js/components/builder/ControlBar.vue` | **Neu** (optional) — CP-getönte Zoom/Fit/Pan/Run-Leiste. |
| `resources/js/composables/useNodeIcon.js` | **Neu** — zentrales handle→Icon-Mapping (von Card + Library genutzt). |
| `resources/js/pages/Automations/Edit.vue` | Top-Bar (Breadcrumb/Status/Undo-Redo/Save-primär). |

---

## 5. Risiken & offene Punkte

- **Undo/Redo** existiert heute nicht (kein History-Stack). Entweder eigenen Command-Stack bauen (Aufwand, eigenes Ticket) oder in v1 weglassen. **Default-Vorschlag:** v1 ohne Undo/Redo, als Follow-up filen.
- **Vue-Flow-CSS-Spezifität:** Die importierten Vue-Flow-Styles sind unscoped und kcommen aus `node_modules`. Overrides brauchen ggf. höhere Spezifität / bewusste Layer-Platzierung. Vor größeren Änderungen Computed-Styles im Browser diffen (CLAUDE.md: UI-Vergleiche über Code/Computed-Styles, nicht Screenshots).
- **„Nichts erfinden":** Properties/Runtime/Performance-Sektionen dürfen nur echte Daten zeigen. Wo Backend-Felder fehlen (z.B. success rate, response time, version), Sektion ausblenden statt Platzhalter-Zahlen. Ggf. zuerst klären, welche Meta-Felder `AutomationsController@show`/`NodeRegistry` liefern.
- **Icon-Verfügbarkeit:** Nicht jeder Node-Handle hat ein passendes Statamic-Icon. Fallback je Kind (Trigger/Action/Logic) definieren; Icon-Set von Statamic prüfen (`@statamic/cms/ui` Icon-Namen).
- **Node-Card-Breite/Handle-Positionen:** Größere Cards (Icon-Chip + Body + Chips) ändern die Handle-Y-Positionen der Branch-Outputs (`top: 40%/70%` in `NodeCard.vue`). Nach Umbau Branch-Edge-Anschlüsse visuell prüfen.
- **Kein Convex/kein neues Framework:** Alles mit bestehendem Vue-Flow + `@statamic/cms/ui`. Keine neuen UI-Libs (CLAUDE.md: kein neues Tool ohne Begründung).
- **Persistente-Layout-Teardown (Bugfix beachten):** Der Editor ist jetzt Single-Root + `isolate` (siehe List↔Detail-Bugfix). Neue Overlays (Control-Bar, Kontextmenüs) so bauen, dass sie beim Route-Wechsel sauber unmounten (reka-ui-Teleports schließen).

---

## 6. Verifikation (browsergestützt, wenn verfügbar)

Pro Schritt: `npm run build` grün → `vendor:publish --force` → im CP prüfen:
1. Übersicht → Editor öffnen: keine Layout-/Overlay-Reste (Regression zum Bugfix).
2. Node-Card: Icon-Chip, Titel, Badge, Menü, Chips, Selected-Ring in Light + Dark.
3. Library: Icons, Counts, Collapse, Suche.
4. Properties: Sektionen kollabieren, Configuration-Formular funktioniert wie vorher, Footer-Action.
5. Top-Bar: Breadcrumb, Status-Pill, Save primär.
6. Control-Bar: Zoom/Fit/Pan/Run steuern denselben Flow (`flowId`).
7. Computed-Styles gegen ein natives Statamic-Panel diffen (Farbe/Radius/Spacing), nicht nur Screenshot.
```
