<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Header, Alert, Badge } from '@statamic/cms/ui';

const props = defineProps({
    title: { type: String, required: true },
    config_path: { type: String, required: true },
    queue: { type: String, default: 'default' },
    queue_connection: { type: [String, null], default: null },
    runs: { type: Object, required: true },
    test_mode: { type: Object, required: true },
    features: { type: Object, required: true },
    redact_keys: { type: Array, required: true },
    integrations: { type: Object, required: true },
    license: { type: Object, required: true },
});

/** Stable ids so each value cell can point at the label that names it. */
function slug(value) {
    return String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function sectionId(title) {
    return `sa-settings-${slug(title)}`;
}

function rowId(title, index) {
    return `${sectionId(title)}-row-${index}`;
}

const testModeMeta = {
    send_real_webhooks: {
        label: __('Send real webhooks'),
        description: __('When blocked, test runs simulate webhook calls instead of sending them.'),
    },
    send_real_emails: {
        label: __('Send real emails'),
        description: __('When blocked, test runs simulate email sends instead of delivering them.'),
    },
    persist_leadhub_changes: {
        label: __('Persist LeadHub changes'),
        description: __('When blocked, test runs do not write changes to LeadHub.'),
    },
    persist_statamic_changes: {
        label: __('Persist Statamic changes'),
        description: __('When blocked, test runs do not write changes to Statamic content.'),
    },
    call_real_ai: {
        label: __('Call the real AI provider'),
        description: __('When blocked, test runs return a canned completion instead of calling (and billing) the provider.'),
    },
};

const integrationMeta = {
    webhook_manager: { label: __('Webhook Manager') },
    leadhub: { label: __('LeadHub') },
};

function humanize(key) {
    return String(key).replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

// Build the settings as native publish-style sections of label/description rows.
const sections = computed(() => {
    const s = [];

    s.push({
        title: __('Queue'),
        rows: [
            { label: __('Queue name'), description: __('The queue automation jobs are dispatched onto.'), mono: props.queue || 'default' },
            { label: __('Connection'), description: __('The queue connection used to dispatch runs.'), mono: props.queue_connection ?? __('default') },
        ],
    });

    s.push({
        title: __('Runs'),
        rows: [
            { label: __('Retention'), description: __('How long completed runs are kept before they are pruned.'), text: `${props.runs.prune_after_days} ${__('days')}` },
            { label: __('Failed runs kept for'), description: __('How long failed runs are retained for debugging.'), text: props.runs.keep_failed_runs_days ?? __('same as default') },
            { label: __('Store full context'), description: __('Persist the full trigger context with each run.'), badge: { color: props.runs.store_full_context ? 'green' : 'default', text: props.runs.store_full_context ? __('Yes') : __('No') } },
            { label: __('Encrypt context'), description: __('Encrypt stored run context at rest.'), badge: { color: props.runs.encrypt_context ? 'green' : 'default', text: props.runs.encrypt_context ? __('Encrypted') : __('Plaintext') } },
        ],
    });

    s.push({
        title: __('Test mode'),
        rows: Object.entries(props.test_mode).map(([key, value]) => ({
            label: testModeMeta[key]?.label ?? humanize(key),
            description: testModeMeta[key]?.description ?? __('Controls whether this side effect runs for real during test runs.'),
            badge: { color: value ? 'amber' : 'default', text: value ? __('Allowed') : __('Blocked') },
        })),
    });

    s.push({
        title: __('Integrations'),
        rows: Object.entries(props.integrations).map(([key, active]) => ({
            label: integrationMeta[key]?.label ?? humanize(key),
            description: __('Sister addon — its triggers and actions register automatically when installed.'),
            badge: { color: active ? 'green' : 'default', text: active ? __('Detected') : __('Not installed') },
        })),
    });

    const licenseRows = [
        { label: __('Mode'), description: __('How the license is verified (config or remote).'), mono: props.license.mode },
        { label: __('Key set'), description: __('Whether a license key is configured.'), badge: { color: props.license.has_key ? 'green' : 'default', text: props.license.has_key ? __('Yes') : __('No') } },
        { label: __('Validation'), description: __('Current license validation status.'), badge: { color: props.license.is_valid ? 'green' : 'amber', text: props.license.is_valid ? __('Valid') : __('No active license') } },
    ];
    if (props.license.features && props.license.features.length) {
        licenseRows.push({ label: __('Pro features'), description: __('Features unlocked by the active license.'), badges: props.license.features });
    }
    s.push({ title: __('License'), rows: licenseRows });

    s.push({
        title: __('Payload redaction'),
        rows: [
            { label: __('Redacted keys'), description: __('Run logs replace the values of these keys with [REDACTED] before storing.'), badges: props.redact_keys.length ? props.redact_keys : null, empty: __('None') },
        ],
    });

    return s;
});
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="sliders-horizontal" />

        <Alert variant="default" class="mb-6">
            {{ __('These settings are read-only here. Edit them in your application config file:') }}
            <code class="ml-1 px-1 rounded bg-gray-100 dark:bg-gray-800 text-xs">{{ config_path }}</code>
        </Alert>

        <div class="space-y-8">
            <section v-for="section in sections" :key="section.title" :aria-labelledby="sectionId(section.title)">
                <h2 :id="sectionId(section.title)" class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2 px-1">
                    {{ section.title }}
                </h2>

                <!-- A description list rendered as a grid. `dl`/`dt`/`dd` would
                     collapse the two-column layout, so the roles are declared
                     instead — otherwise this reads as unrelated text runs. -->
                <div
                    class="rounded-xl border border-gray-200 dark:border-gray-800 bg-content-bg shadow-sm divide-y divide-gray-200 dark:divide-gray-800"
                    role="list"
                >
                    <div
                        v-for="(row, i) in section.rows"
                        :key="i"
                        role="listitem"
                        class="grid md:grid-cols-2 items-start gap-x-6 gap-y-1.5 px-5 py-4"
                    >
                        <div class="min-w-0">
                            <div :id="rowId(section.title, i)" class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ row.label }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ row.description }}</div>
                        </div>

                        <div class="text-sm md:pt-0.5" :aria-labelledby="rowId(section.title, i)">
                            <code v-if="row.mono !== undefined" class="text-xs">{{ row.mono }}</code>
                            <span v-else-if="row.text !== undefined">{{ row.text }}</span>
                            <Badge v-else-if="row.badge" :color="row.badge.color" :text="row.badge.text" />
                            <div v-else-if="row.badges" class="flex flex-wrap gap-1">
                                <Badge v-for="b in row.badges" :key="b" color="default" :text="b" />
                            </div>
                            <span v-else class="text-gray-400">{{ row.empty ?? '—' }}</span>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
