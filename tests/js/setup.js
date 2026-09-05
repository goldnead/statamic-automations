/**
 * Vitest bootstrap for the Control Panel components.
 *
 * `@statamic/cms/ui` and `@statamic/cms/inertia` are thin re-export shims:
 *
 *     export const { Alert, Badge, Button, … } = __STATAMIC__.ui;
 *
 * `__STATAMIC__` is put on the window by the Control Panel at runtime. In a
 * test process nobody does that, so the destructure would throw before a
 * single assertion ran — every test would fail at an import instead of at the
 * thing under test. We install the global here, before any test module is
 * imported, and answer every requested name with a stub component.
 *
 * The stubs render a `<div data-stub="Badge" data-attr-text="…">`: every
 * scalar attribute is mirrored into the DOM, so a test can assert both that a
 * component was rendered and what it was handed — without depending on the
 * real CP markup, which is not ours to pin down.
 */
import { defineComponent, h, reactive } from 'vue';
import { config } from '@vue/test-utils';

const stubs = new Map();

/**
 * A component that renders its name, its scalar attributes and its slot.
 *
 * `:mode="responseMode()"` becomes `data-attr-mode="json"`, so a test asserts
 * against the DOM instead of poking at component internals, and can locate a
 * component by what it was given (`[data-attr-heading="Response"]`) rather
 * than by its position in a list.
 */
function stubComponent(name) {
    if (!stubs.has(name)) {
        stubs.set(name, defineComponent({
            name,
            inheritAttrs: false,
            setup(_props, { attrs, slots }) {
                return () => {
                    const rendered = { 'data-stub': name };
                    const text = [];

                    for (const [key, value] of Object.entries(attrs)) {
                        if (value === null || value === undefined) continue;

                        // Event listeners are forwarded to the root element.
                        // `inheritAttrs: false` would otherwise drop them, and
                        // a stubbed <Button> that cannot be clicked makes every
                        // interaction in the component under test unreachable —
                        // which is most of what there is to test.
                        if (key.startsWith('on') && typeof value === 'function') {
                            rendered[key] = value;
                            continue;
                        }

                        if (typeof value === 'object' || typeof value === 'function') continue;

                        // `data-testid` and friends pass through untouched so
                        // a test can select by the marker the template sets.
                        rendered[
                            key.startsWith('data-')
                                ? key
                                : 'data-attr-' + key.replace(/([A-Z])/g, '-$1').toLowerCase()
                        ] = String(value);

                        // Text-carrying props also go into the text content so
                        // `wrapper.text()` reads like the rendered page does.
                        if (['text', 'heading', 'title', 'label'].includes(key)) {
                            text.push(h('span', String(value)));
                        }
                    }

                    return h('div', rendered, [...text, slots.default ? slots.default() : null]);
                };
            },
        }));
    }

    return stubs.get(name);
}

/**
 * `Listing` is the one component that cannot be a generic stub.
 *
 * The generic stub renders the default slot and nothing else, and a listing's
 * whole content lives in named slots — `cell-<field>` per column,
 * `prepended-row-actions` per row. Stubbed generically, every table in this
 * addon renders as an empty div and every assertion about a row passes or fails
 * for reasons that have nothing to do with the component under test.
 *
 * So this one mirrors the real component's structure closely enough to test
 * against: a header cell per column, a row per item, a cell per column filled
 * from the matching slot (falling back to the raw value, as core's TableField
 * does), and one more cell holding the row menu. It deliberately does NOT sort,
 * paginate or filter — that is core's code, not this addon's, and a stub that
 * reimplemented it would be asserting against itself.
 */
const listingStub = defineComponent({
    name: 'Listing',
    inheritAttrs: false,
    setup(_props, { attrs, slots }) {
        return () => {
            const items = Array.isArray(attrs.items) ? attrs.items : [];
            const columns = (Array.isArray(attrs.columns) ? attrs.columns : [])
                .map((column) => (typeof column === 'string' ? { field: column, label: column } : column));

            const head = h('thead', [h('tr', columns.map((column) => h(
                'th',
                { key: column.field, 'data-column-head': column.field, 'data-sortable': String(column.sortable !== false) },
                column.label ?? column.field,
            )))]);

            const body = h('tbody', items.map((row) => h('tr', { key: row.id, 'data-listing-row': String(row.id) }, [
                ...columns.map((column) => {
                    const slot = slots[`cell-${column.field}`];
                    const value = row[column.value || column.field];

                    return h(
                        'td',
                        { key: column.field, 'data-column': column.field },
                        slot ? slot({ value, row }) : String(value ?? ''),
                    );
                }),
                h('td', { 'data-row-actions': '' }, slots['prepended-row-actions']
                    ? slots['prepended-row-actions']({ row })
                    : null),
            ])));

            return h('div', {
                'data-stub': 'Listing',
                // The checkbox column is core's answer to `action-url`; a test
                // asserting that a selection can do something needs to see
                // whether one was offered at all.
                'data-attr-action-url': attrs.actionUrl ?? attrs['action-url'] ?? '',
            }, [h('table', [head, body])]);
        };
    },
});

const namedStubs = { Listing: listingStub };

/** Anything asked of `__STATAMIC__.ui` is a component. */
const componentBag = () => new Proxy({}, {
    get: (_target, prop) => {
        if (typeof prop !== 'string') return undefined;

        return namedStubs[prop] ?? stubComponent(prop);
    },
});

/** `inertia` mixes components (Head, Link) with plain helpers (router). */
const inertiaHelpers = {
    router: {
        reload: () => {},
        visit: () => {},
        get: () => {},
        post: () => {},
    },
    useForm: (data) => reactive({ ...data, processing: false, errors: {} }),
    usePoll: () => {},
    toggleArchitecturalBackground: () => {},
    useArchitecturalBackground: () => ({}),
};

globalThis.__STATAMIC__ = {
    ui: componentBag(),
    api: componentBag(),
    bard: componentBag(),
    cms: componentBag(),
    'save-pipeline': componentBag(),
    inertia: new Proxy(inertiaHelpers, {
        get: (target, prop) => (
            prop in target
                ? target[prop]
                : (typeof prop === 'string' ? stubComponent(prop) : undefined)
        ),
    }),
};

// The CP exposes the translator as a global template helper. Templates call
// `__('Delivery')`; returning the key keeps assertions readable.
//
// The real translator also substitutes `:placeholders` from the second
// argument. The stub does too — without it a component that builds a sentence
// out of data (`__('Delete ":name"?', { name: row.name })`) is untestable: the
// assertion can only ever see the raw key, so it cannot tell a correct
// substitution from a missing one.
const translate = (key, replacements = {}) => {
    const source = Array.isArray(key) ? key.filter(Boolean).join(' ') : String(key ?? '');

    return Object.entries(replacements ?? {}).reduce(
        (carry, [token, value]) => carry.split(`:${token}`).join(String(value)),
        source,
    );
};

config.global.mocks = { __: translate };

// The Control Panel also puts `__` on the window, and this addon's components
// call it from `<script setup>` as well as from templates (option lists,
// computed labels). A template mock alone is not reached from there, so the
// component would die at setup with "__ is not defined" — before a single
// assertion ran, and for a reason that has nothing to do with what is being
// tested. Installed globally, exactly as the CP does it.
globalThis.__ = translate;

// Statamic's remaining Vue 2-era global components are not registered here.
config.global.stubs = {
    'date-time': true,
    'svg-icon': true,
};
