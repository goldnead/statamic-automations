/**
 * Statamic Automations — CP entry point
 *
 * Statamic 6 uses Inertia.js to dispatch CP pages to Vue components.
 * This file does NOT mount its own Vue app — it registers our pages
 * with Statamic's Inertia plugin, which then renders them inside the
 * native CP layout.
 *
 * See https://statamic.dev/extending/control-panel#inertia-pages
 */

import '../css/cp.css';

import Dashboard from './pages/Dashboard.vue';
import AutomationsIndex from './pages/Automations/Index.vue';
import AutomationsEdit from './pages/Automations/Edit.vue';
import RunsIndex from './pages/Runs/Index.vue';
import RunsShow from './pages/Runs/Show.vue';
import TemplatesIndex from './pages/Templates/Index.vue';
import ImportPage from './pages/Import.vue';
import SettingsShow from './pages/Settings/Show.vue';
import AuditIndex from './pages/Audit/Index.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-automations::Dashboard', Dashboard);
    Statamic.$inertia.register('statamic-automations::Automations/Index', AutomationsIndex);
    Statamic.$inertia.register('statamic-automations::Automations/Edit', AutomationsEdit);
    Statamic.$inertia.register('statamic-automations::Runs/Index', RunsIndex);
    Statamic.$inertia.register('statamic-automations::Runs/Show', RunsShow);
    Statamic.$inertia.register('statamic-automations::Templates/Index', TemplatesIndex);
    Statamic.$inertia.register('statamic-automations::Import', ImportPage);
    Statamic.$inertia.register('statamic-automations::Settings/Show', SettingsShow);
    Statamic.$inertia.register('statamic-automations::Audit/Index', AuditIndex);
});
