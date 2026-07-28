import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';

import { computeLayout } from '../../resources/js/composables/useAutoLayout.js';
import Show from '../../resources/js/pages/Settings/Show.vue';

/**
 * `??` only falls through on null and undefined. Everywhere the builder reads a
 * value the backend may also store as an empty string, that is the wrong
 * operator — the empty string survives and is then used as if it were a handle,
 * a label or a message.
 */

describe('an edge whose output handle is an empty string', () => {
    const nodes = [
        { node_key: 'trigger_1', type: 'manual', config: {} },
        { node_key: 'log_1', type: 'add_log_entry', config: {} },
    ];

    it('is treated as the default output, not as a handle called ""', () => {
        // `edges.*.from_output` is `['nullable', 'string']`, so '' is valid
        // input, and `$edge['from_output'] ?? 'default'` on the way in does not
        // replace it. Read back with `??`, the canvas keyed the wired output as
        // `trigger_1::''`, which matches no real handle: the edge drew no line
        // and the node kept offering a "+" adder on an output it was already
        // connected to. Both symptoms, one operator.
        const layout = computeLayout(nodes, [
            { from_node_key: 'trigger_1', from_output: '', to_node_key: 'log_1' },
        ]);

        const openFromTrigger = layout.openOutputs.filter((o) => o.from_node_key === 'trigger_1');

        expect(openFromTrigger).toHaveLength(0);
    });

    it('still leaves a genuinely unwired output open', () => {
        const layout = computeLayout(nodes, []);

        expect(layout.openOutputs.filter((o) => o.from_node_key === 'trigger_1')).toHaveLength(1);
    });

    it('places the target below its source rather than as a second root', () => {
        const layout = computeLayout(nodes, [
            { from_node_key: 'trigger_1', from_output: '', to_node_key: 'log_1' },
        ]);

        expect(layout.positions.log_1.y).toBeGreaterThan(layout.positions.trigger_1.y);
    });
});

describe('the settings screen', () => {
    it('shows the effective queue name when the env var is set but empty', () => {
        // `config/automations.php` is `env('STATAMIC_AUTOMATIONS_QUEUE', 'default')`.
        // An empty `STATAMIC_AUTOMATIONS_QUEUE=` in .env yields '', which `??`
        // passes straight through, and the row rendered blank — reading as if
        // no queue were configured at all.
        const wrapper = mount(Show, {
            props: {
                title: 'Settings',
                config_path: 'config/automations.php',
                queue: '',
                // A real value, so the assertion below cannot be satisfied by
                // the connection row's own (correct) `?? __('default')`.
                queue_connection: 'redis',
                runs: { prune_after_days: 30, keep_failed_runs_days: null },
                test_mode: {},
                features: {},
                redact_keys: [],
                integrations: {},
                license: { edition: 'pro' },
            },
        });

        const values = wrapper.findAll('code').map((el) => el.text());

        expect(values).toContain('default');
        expect(values).not.toContain('');
    });
});
