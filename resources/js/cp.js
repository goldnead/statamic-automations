import { createApp } from 'vue';
import AutomationBuilder from './components/AutomationBuilder.vue';
import AutomationsList from './components/AutomationsList.vue';
import RunsList from './components/RunsList.vue';
import RunDetail from './components/RunDetail.vue';
import Templates from './components/Templates.vue';
import ImportPage from './components/ImportPage.vue';

import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import '../sass/automations.scss';

/**
 * Mount whichever component matches a `data-automations-app` attribute
 * on the page. The Statamic Blade views render an empty container with
 * the right attribute and we hydrate it here.
 */
const apps = {
    builder: AutomationBuilder,
    list: AutomationsList,
    runs: RunsList,
    'run-detail': RunDetail,
    templates: Templates,
    import: ImportPage,
};

document.querySelectorAll('[data-automations-app]').forEach((el) => {
    const appName = el.getAttribute('data-automations-app');
    const Component = apps[appName];

    if (!Component) {
        console.warn(`[automations] unknown app: ${appName}`);
        return;
    }

    const props = {};
    for (const attr of el.attributes) {
        if (attr.name.startsWith('data-prop-')) {
            const key = attr.name.replace('data-prop-', '').replace(/-/g, '_');
            try {
                props[key] = JSON.parse(attr.value);
            } catch {
                props[key] = attr.value;
            }
        }
    }

    createApp(Component, props).mount(el);
});
