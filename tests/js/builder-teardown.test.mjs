/**
 * Regression test — CP builder page teardown on Inertia navigation.
 *
 * Statamic's Control Panel is an Inertia SPA with a *persistent* layout: every
 * page renders into one shared `<slot>` inside `#content-card`. The Automations
 * overview (a `<Listing>`) and the builder/editor (a Vue Flow canvas) swap in and
 * out of that same slot.
 *
 * Bug this guards against: after navigating from the overview into the builder,
 * the overview listing stayed painted over the canvas until a page reload
 * (see STATE/backlog/assets/statamic-automations-list-detail-bug.png).
 *
 * These tests reproduce Inertia's exact persistent-layout render + swap pattern
 * (kp = h(component); page vnode keyed by a timestamp ref) and assert that:
 *   1. leaving a listing page removes its DOM from the shared slot, and
 *   2. the builder page presents a SINGLE root node to the slot, so the swap is
 *      unambiguous and nothing from the previous page can linger beside it.
 *
 * Run: `npm run test:js`
 */
import assert from 'node:assert/strict';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><head></head><body><div id="app"></div></body></html>', {
    pretendToBeVisual: true,
});
globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.requestAnimationFrame = (cb) => setTimeout(cb, 0);
for (const k of ['SVGElement', 'Element', 'Node', 'HTMLElement']) globalThis[k] = dom.window[k];

const { h, ref, defineComponent, createApp, nextTick, Comment, markRaw } = await import('vue');

// Stand-in for Statamic's <Head> — renders nothing into the body (head manager).
const HeadStub = defineComponent({ name: 'Head', render: () => h(Comment, 'head') });

// Overview page: mirrors Automations/Index.vue — a listing.
const OverviewPage = markRaw(
    defineComponent({
        name: 'OverviewPage',
        render: () => [h(HeadStub), h('div', { class: 'listing', 'data-page': 'overview' }, 'overview listing')],
    }),
);

// Builder page: mirrors the FIXED Automations/Edit.vue — a SINGLE root element
// with <Head> and the run-log stack nested inside it.
const BuilderPage = markRaw(
    defineComponent({
        name: 'BuilderPage',
        render: () =>
            h('div', { class: 'relative isolate', 'data-page': 'builder' }, [
                h(HeadStub),
                h('div', { class: 'canvas' }, 'vue flow canvas'),
            ]),
    }),
);

// Persistent layout: #content-card > [data-max-width-wrapper] > <slot/>.
const Layout = markRaw(
    defineComponent({
        name: 'Layout',
        render() {
            return h('main', h('div', { id: 'content-card' }, h('div', { 'data-mww': '' }, this.$slots.default())));
        },
    }),
);
OverviewPage.layout = Layout;
BuilderPage.layout = Layout;

let containerSeq = 0;

function mountInertia() {
    const container = document.createElement('div');
    container.id = `app-${containerSeq++}`;
    document.body.appendChild(container);

    // Inertia stores h(component) and assigns the persistent layout onto the vnode.
    const asPage = (component) => {
        const vnode = h(component);
        vnode.layout = component.layout;
        return vnode;
    };
    const kp = ref(asPage(OverviewPage));
    const props = ref({});
    const key = ref(undefined);

    const App = defineComponent({
        name: 'App',
        setup() {
            return () => {
                kp.value.inheritAttrs = !!kp.value.inheritAttrs;
                const page = h(kp.value, { ...props.value, key: key.value }); // cloneVNode + re-key
                const layout = kp.value.layout;
                return layout
                    ? [layout].concat(page).reverse().reduce((e, t) => (t.inheritAttrs = !!t.inheritAttrs, h(t, { ...props.value }, () => e)))
                    : page;
            };
        },
    });

    const app = createApp(App);
    app.mount(container);

    // Mirrors Inertia's swapComponent: key changes unless preserveState.
    const swap = (component, preserveState = false) => {
        kp.value = asPage(component);
        key.value = preserveState ? key.value : Date.now() + Math.random();
    };
    const q = (sel) => container.querySelector(sel);
    const slot = () => container.querySelector('[data-mww]');
    return { swap, q, slot };
}

test('leaving the overview removes its listing DOM from the shared slot', async () => {
    const { swap, q, slot } = mountInertia();
    await nextTick();
    assert.ok(q('[data-page="overview"]'), 'overview should render initially');

    swap(BuilderPage);
    await nextTick();

    assert.equal(q('[data-page="overview"]'), null, 'overview listing must be torn down');
    assert.ok(q('[data-page="builder"]'), 'builder should be mounted');
    assert.equal(slot().children.length, 1, 'the shared slot must hold exactly one page');
});

test('builder page presents a single root node to the persistent-layout slot', async () => {
    const { swap, slot } = mountInertia();
    await nextTick();
    swap(BuilderPage);
    await nextTick();

    const builderRoots = [...slot().children].filter((el) => el.getAttribute('data-page') === 'builder');
    assert.equal(builderRoots.length, 1, 'builder must be a single-root page');
    assert.equal(slot().children.length, 1, 'no sibling nodes may remain beside the builder root');
});

test('navigating back to the overview removes the builder canvas', async () => {
    const { swap, q } = mountInertia();
    swap(BuilderPage);
    await nextTick();
    swap(OverviewPage);
    await nextTick();

    assert.equal(q('.canvas'), null, 'builder canvas must be gone after leaving');
    assert.ok(q('[data-page="overview"]'), 'overview should be back');
});
