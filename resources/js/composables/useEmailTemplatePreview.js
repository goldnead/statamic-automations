import { ref } from 'vue';
import axios from 'axios';

/**
 * Session caches shared by every consumer of the email-template endpoints, so
 * re-opening the preview/picker (or highlighting a template a second time) is
 * instant instead of re-fetching. Keyed the same way the endpoints are scoped:
 * the list by `apiBase`, each rendered preview by its `slug`.
 */
const listCache = new Map(); // apiBase -> [{ slug, title, subject, preview }]
const htmlCache = new Map(); // slug    -> { slug, title, subject, preview, html }

/**
 * Fetches the flat list of managed email templates for the picker from
 * `GET {apiBase}/email-templates` (see `EmailTemplatePreviewController::index`).
 * Uses the same bare `axios` instance as the rest of the addon's CP requests
 * (same-origin, XSRF cookie handles CSRF).
 */
export function useEmailTemplateList(apiBase) {
    const templates = ref([]);
    const loading = ref(false);
    const error = ref(null);

    async function load(force = false) {
        if (!apiBase) {
            templates.value = [];
            return;
        }

        if (!force && listCache.has(apiBase)) {
            templates.value = listCache.get(apiBase);
            error.value = null;
            return;
        }

        loading.value = true;
        error.value = null;

        try {
            const { data } = await axios.get(`${apiBase}/email-templates`);
            const result = Array.isArray(data) ? data : (data?.data ?? []);
            listCache.set(apiBase, result);
            templates.value = result;
        } catch (err) {
            error.value = err;
            templates.value = [];
        } finally {
            loading.value = false;
        }
    }

    return { templates, loading, error, load };
}

/**
 * Fetches a single rendered template (branded HTML + subject + preheader) from
 * `GET {apiBase}/email-templates/preview?slug=…`, with sample merge tokens
 * already resolved server-side. Guards against out-of-order responses so a
 * fast keyboard walk through the picker never shows a stale preview.
 */
export function useEmailTemplatePreview(apiBase) {
    const preview = ref(null);
    const loading = ref(false);
    const error = ref(null);
    let seq = 0;

    async function load(slug) {
        if (!slug || !apiBase) {
            preview.value = null;
            error.value = null;
            loading.value = false;
            return;
        }

        if (htmlCache.has(slug)) {
            preview.value = htmlCache.get(slug);
            error.value = null;
            loading.value = false;
            return;
        }

        const my = ++seq;
        loading.value = true;
        error.value = null;

        try {
            const { data } = await axios.get(`${apiBase}/email-templates/preview`, { params: { slug } });
            if (my !== seq) return; // superseded by a newer request
            const result = data?.data ?? data;
            htmlCache.set(slug, result);
            preview.value = result;
        } catch (err) {
            if (my !== seq) return;
            error.value = err;
            preview.value = null;
        } finally {
            if (my === seq) loading.value = false;
        }
    }

    function clear() {
        preview.value = null;
        error.value = null;
    }

    return { preview, loading, error, load, clear };
}
