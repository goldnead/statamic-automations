import { ref, unref, watch } from 'vue';
import axios from 'axios';

/**
 * Module-level cache shared by every `useNodeOptions()` call in the app.
 * Keyed by `source::JSON(params)` so identical (source, params) pairs are
 * only fetched once per page load, regardless of how many fields/nodes
 * request them.
 */
const cache = new Map();

/**
 * Fetches options for a node-config `select` field from the addon's
 * `GET {cp}/automations/api/options/{source}` endpoint (see
 * `NodesController::options()`), using the same bare `axios` instance the
 * rest of the addon's Vue pages already use for CP requests (no custom
 * axios setup, no separate base URL — same-origin CP routes handle CSRF
 * via the browser's XSRF cookie automatically, same as the addon's
 * existing `axios.post(...)`/`axios.get(...)` calls in
 * `Automations/Edit.vue` and `Automations/Index.vue`).
 *
 * @param {string|import('vue').Ref<string|null>} source - `options_source`
 *   handle from the field schema (e.g. `statamic.collections`, `entries`).
 *   May be a plain string or a ref/computed if the source itself is dynamic.
 * @param {import('vue').Ref<Object>|(() => Object)} paramsRef - reactive
 *   query params (e.g. `{ collection: 'blog' }` for a cascading `entries`
 *   picker). Re-fetches whenever the resolved params change.
 * @param {string} apiBase - the addon's CP API base URL (the same
 *   `apiBase` prop already threaded through `ConfigPanel.vue` /
 *   `Automations/Edit.vue`, e.g. `cp_route('statamic-automations.api.index')`).
 * @returns {{ options: import('vue').Ref<Array>, loading: import('vue').Ref<boolean>, error: import('vue').Ref<*> }}
 */
export function useNodeOptions(source, paramsRef, apiBase) {
    const options = ref([]);
    const loading = ref(false);
    const error = ref(null);

    let requestSeq = 0;

    function resolveParams() {
        const value = typeof paramsRef === 'function' ? paramsRef() : unref(paramsRef);
        return value && typeof value === 'object' ? value : {};
    }

    async function fetchOptions() {
        const sourceValue = unref(source);
        const params = resolveParams();

        if (!sourceValue || !apiBase) {
            options.value = [];
            loading.value = false;
            error.value = null;
            return;
        }

        const cacheKey = `${sourceValue}::${JSON.stringify(params)}`;

        if (cache.has(cacheKey)) {
            options.value = cache.get(cacheKey);
            loading.value = false;
            error.value = null;
            return;
        }

        const seq = ++requestSeq;
        loading.value = true;
        error.value = null;

        try {
            const { data } = await axios.get(`${apiBase}/options/${sourceValue}`, { params });
            const result = Array.isArray(data) ? data : (data?.data ?? []);

            if (seq !== requestSeq) return; // stale response, a newer request superseded it

            cache.set(cacheKey, result);
            options.value = result;
        } catch (err) {
            if (seq !== requestSeq) return;

            // Never hard-fail on an empty/errored options response — the
            // caller shows an empty/hint state instead of a crash.
            error.value = err;
            options.value = [];
        } finally {
            if (seq === requestSeq) {
                loading.value = false;
            }
        }
    }

    watch(
        [() => unref(source), () => JSON.stringify(resolveParams())],
        fetchOptions,
        { immediate: true },
    );

    return { options, loading, error };
}
