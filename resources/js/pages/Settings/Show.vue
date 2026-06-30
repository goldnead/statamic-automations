<script setup>
import { Head } from '@statamic/cms/inertia';
import {
    Header,
    Panel,
    Alert,
    Badge,
} from '@statamic/cms/ui';

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

// Human-readable label + description for the test-mode side-effect flags.
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
};

const integrationMeta = {
    webhook_manager: { label: __('Webhook Manager') },
    leadhub: { label: __('LeadHub') },
};

function humanize(key) {
    return String(key)
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function testModeLabel(key) {
    return testModeMeta[key]?.label ?? humanize(key);
}

function testModeDescription(key) {
    return testModeMeta[key]?.description ?? __('Controls whether this side effect runs for real during test runs.');
}

function integrationLabel(key) {
    return integrationMeta[key]?.label ?? humanize(key);
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="settings-horizontal" />

        <Alert variant="info" class="mb-6">
            {{ __('These settings are read-only here. Edit them in your application config file:') }}
            <code class="ml-1 px-1 rounded bg-gray-100 dark:bg-gray-800 text-xs">{{ config_path }}</code>
        </Alert>

        <div class="space-y-6">
            <Panel :heading="__('Queue')">
                <div class="divide-y divide-content-border">
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Queue name') }}</div>
                            <div class="text-sm text-gray-500">{{ __('The queue automation jobs are dispatched onto.') }}</div>
                        </div>
                        <div class="shrink-0 text-sm"><code class="text-xs">{{ queue ?? 'default' }}</code></div>
                    </div>
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Connection') }}</div>
                            <div class="text-sm text-gray-500">{{ __('The queue connection used to dispatch runs.') }}</div>
                        </div>
                        <div class="shrink-0 text-sm"><code class="text-xs">{{ queue_connection ?? __('default') }}</code></div>
                    </div>
                </div>
            </Panel>

            <Panel :heading="__('Runs')">
                <div class="divide-y divide-content-border">
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Retention') }}</div>
                            <div class="text-sm text-gray-500">{{ __('How long completed runs are kept before they are pruned.') }}</div>
                        </div>
                        <div class="shrink-0 text-sm">{{ runs.prune_after_days }} {{ __('days') }}</div>
                    </div>
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Failed runs kept for') }}</div>
                            <div class="text-sm text-gray-500">{{ __('How long failed runs are retained for debugging.') }}</div>
                        </div>
                        <div class="shrink-0 text-sm">{{ runs.keep_failed_runs_days ?? __('same as default') }}</div>
                    </div>
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Store full context') }}</div>
                            <div class="text-sm text-gray-500">{{ __('Persist the full trigger context with each run.') }}</div>
                        </div>
                        <div class="shrink-0">
                            <Badge :color="runs.store_full_context ? 'green' : 'gray'" :text="runs.store_full_context ? __('Yes') : __('No')" />
                        </div>
                    </div>
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Encrypt context') }}</div>
                            <div class="text-sm text-gray-500">{{ __('Encrypt stored run context at rest.') }}</div>
                        </div>
                        <div class="shrink-0">
                            <Badge :color="runs.encrypt_context ? 'green' : 'gray'" :text="runs.encrypt_context ? __('Encrypted') : __('Plaintext')" />
                        </div>
                    </div>
                </div>
            </Panel>

            <Panel :heading="__('Test mode')">
                <div class="divide-y divide-content-border">
                    <div v-for="(value, key) in test_mode" :key="key" class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ testModeLabel(key) }}</div>
                            <div class="text-sm text-gray-500">{{ testModeDescription(key) }}</div>
                        </div>
                        <div class="shrink-0">
                            <Badge :color="value ? 'amber' : 'gray'" :text="value ? __('Allowed') : __('Blocked')" />
                        </div>
                    </div>
                </div>
            </Panel>

            <Panel :heading="__('Integrations')">
                <div class="divide-y divide-content-border">
                    <div v-for="(active, key) in integrations" :key="key" class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ integrationLabel(key) }}</div>
                            <div class="text-sm text-gray-500">{{ __('Sister addon — its triggers and actions register automatically when installed.') }}</div>
                        </div>
                        <div class="shrink-0">
                            <Badge :color="active ? 'green' : 'gray'" :text="active ? __('Detected') : __('Not installed')" />
                        </div>
                    </div>
                </div>
            </Panel>

            <Panel :heading="__('License')">
                <div class="divide-y divide-content-border">
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Mode') }}</div>
                            <div class="text-sm text-gray-500">{{ __('How the license is verified (config or remote).') }}</div>
                        </div>
                        <div class="shrink-0 text-sm"><code class="text-xs">{{ license.mode }}</code></div>
                    </div>
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Key set') }}</div>
                            <div class="text-sm text-gray-500">{{ __('Whether a license key is configured.') }}</div>
                        </div>
                        <div class="shrink-0">
                            <Badge :color="license.has_key ? 'green' : 'gray'" :text="license.has_key ? __('Yes') : __('No')" />
                        </div>
                    </div>
                    <div class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Validation') }}</div>
                            <div class="text-sm text-gray-500">{{ __('Current license validation status.') }}</div>
                        </div>
                        <div class="shrink-0">
                            <Badge :color="license.is_valid ? 'green' : 'amber'" :text="license.is_valid ? __('Valid') : __('No active license')" />
                        </div>
                    </div>
                    <div v-if="license.features && license.features.length" class="flex items-start justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Pro features') }}</div>
                            <div class="text-sm text-gray-500">{{ __('Features unlocked by the active license.') }}</div>
                        </div>
                        <div class="shrink-0 flex flex-wrap justify-end gap-1">
                            <Badge v-for="f in license.features" :key="f" color="blue" :text="f" />
                        </div>
                    </div>
                </div>
            </Panel>

            <Panel :heading="__('Payload redaction')">
                <div class="px-4 py-3">
                    <p class="text-sm text-gray-500 mb-2">
                        {{ __('Run logs replace the values of these keys with [REDACTED] before storing.') }}
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <Badge v-for="key in redact_keys" :key="key" color="gray" :text="key" />
                        <span v-if="!redact_keys.length" class="text-sm text-gray-400">{{ __('None') }}</span>
                    </div>
                </div>
            </Panel>
        </div>
    </div>
</template>
