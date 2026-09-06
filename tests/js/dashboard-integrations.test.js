import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import Dashboard from '../../resources/js/pages/Dashboard.vue';

/**
 * The detected sister addons, on the dashboard.
 *
 * This panel used to sit at the foot of this addon's own settings screen. That
 * screen moved into brand-context on 2026-09-06, and the shared layer takes
 * editable settings only — whether a sister addon is installed is composer's
 * answer, not an operator's. So the panel moved here rather than being lost in
 * the move, and this file is what says it survived: it is the only place in the
 * Control Panel where a human can see what the engine detected.
 */

const props = (overrides = {}) => ({
    title: 'Automations',
    stats: { automations: 0, enabled: 0, runs_30d: 0, success_rate: null, failed_30d: 0 },
    trend: [],
    recentFailures: [],
    failureColumns: [],
    createUrl: '/cp/automations/create',
    automationsUrl: '/cp/automations',
    runsUrl: '/cp/automations/runs',
    canCreate: true,
    integrations: { leadhub: true, webhook_manager: false },
    ...overrides,
});

describe('the dashboard integrations panel', () => {
    it('shows one row per detected integration', () => {
        const wrapper = mount(Dashboard, { props: props() });

        expect(wrapper.find('[data-integration="leadhub"]').exists()).toBe(true);
        expect(wrapper.find('[data-integration="webhook_manager"]').exists()).toBe(true);
    });

    it('says which are installed and which are not', () => {
        const wrapper = mount(Dashboard, { props: props() });

        expect(wrapper.find('[data-integration="leadhub"]').html()).toContain('Detected');
        expect(wrapper.find('[data-integration="webhook_manager"]').html()).toContain('Not installed');
    });

    it('names an integration it has no label for', () => {
        // The detector reports eight keys and this file lists two. A sister
        // addon added there must appear here without a second edit — a
        // hand-kept list is what makes a screen quietly incomplete.
        const wrapper = mount(Dashboard, { props: props({ integrations: { flow_canvas: true } }) });

        expect(wrapper.find('[data-integration="flow_canvas"]').text()).toContain('Flow Canvas');
    });
});
