import axios from 'axios';

/**
 * Build a thin axios wrapper that points at the addon's CP-JSON API.
 *
 * The base URL is read from a meta tag the Blade layout injects, with
 * a sensible default for local development.
 */
function baseUrl() {
    const meta = document.querySelector('meta[name="automations-base"]');
    if (meta && meta.getAttribute('content')) {
        return meta.getAttribute('content');
    }
    return '/cp/automations/api';
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

const client = axios.create({
    baseURL: baseUrl(),
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
});

client.interceptors.request.use((config) => {
    const token = csrfToken();
    if (token && ['post', 'patch', 'put', 'delete'].includes(config.method)) {
        config.headers['X-CSRF-TOKEN'] = token;
    }
    return config;
});

export const api = {
    automations: {
        list: (params = {}) => client.get('/automations', { params }).then((r) => r.data),
        get: (id) => client.get(`/automations/${id}`).then((r) => r.data.data),
        create: (data) => client.post('/automations', data).then((r) => r.data.data),
        update: (id, data) => client.patch(`/automations/${id}`, data).then((r) => r.data.data),
        destroy: (id) => client.delete(`/automations/${id}`).then((r) => r.data),
        duplicate: (id) => client.post(`/automations/${id}/duplicate`).then((r) => r.data.data),
        validate: (id) => client.post(`/automations/${id}/validate`).then((r) => r.data),
        enable: (id) => client.post(`/automations/${id}/enable`).then((r) => r.data),
        disable: (id) => client.post(`/automations/${id}/disable`).then((r) => r.data),
        test: (id, context) => client.post(`/automations/${id}/test`, { context }).then((r) => r.data),
    },
    nodes: {
        index: () => client.get('/nodes').then((r) => r.data),
        describe: (handle) => client.get(`/nodes/${handle}`).then((r) => r.data.data),
        contextSchema: (handle) => client.get(`/context-schema/${handle}`).then((r) => r.data.data),
        options: (source) => client.get(`/options/${source}`).then((r) => r.data.data),
    },
    runs: {
        list: (params = {}) => client.get('/runs', { params }).then((r) => r.data),
        get: (id) => client.get(`/runs/${id}`).then((r) => r.data.data),
        retry: (id) => client.post(`/runs/${id}/retry`).then((r) => r.data),
    },
    templates: {
        list: () => client.get('/templates').then((r) => r.data.data),
        install: (handle) => client.post(`/templates/${handle}/install`).then((r) => r.data.data),
    },
    settings: {
        show: () => client.get('/settings').then((r) => r.data.data),
    },
};

export default client;
