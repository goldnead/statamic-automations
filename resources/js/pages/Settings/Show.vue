<script setup>
import { Head } from '@statamic/cms/inertia';
import {
    Header,
    Panel,
    Alert,
    Badge,
    CodeEditor,
} from '@statamic/cms/ui';

defineProps({
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
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="settings-horizontal" />

        <Alert variant="info" class="mb-4">
            {{ __('Most automation settings live in your application config file. Edit them in:') }}
            <code class="ml-1 text-xs">{{ config_path }}</code>
        </Alert>

        <Panel :heading="__('Queue')" class="mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Queue name') }}</dt>
                    <dd><code class="text-xs">{{ queue ?? 'default' }}</code></dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Connection') }}</dt>
                    <dd><code class="text-xs">{{ queue_connection ?? __('default') }}</code></dd>
                </div>
            </dl>
        </Panel>

        <Panel :heading="__('Runs')" class="mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Retention') }}</dt>
                    <dd>{{ runs.prune_after_days }} {{ __('days') }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Failed kept for') }}</dt>
                    <dd>{{ runs.keep_failed_runs_days ?? __('same as default') }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Store full context') }}</dt>
                    <dd><Badge :color="runs.store_full_context ? 'green' : 'gray'" :text="runs.store_full_context ? __('Yes') : __('No')" /></dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Encrypt context') }}</dt>
                    <dd><Badge :color="runs.encrypt_context ? 'green' : 'gray'" :text="runs.encrypt_context ? __('Encrypted') : __('Plaintext')" /></dd>
                </div>
            </dl>
        </Panel>

        <Panel :heading="__('Test mode')" class="mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div v-for="(value, key) in test_mode" :key="key">
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ key }}</dt>
                    <dd><Badge :color="value ? 'amber' : 'gray'" :text="value ? __('Allowed') : __('Blocked')" /></dd>
                </div>
            </dl>
        </Panel>

        <Panel :heading="__('Integrations')" class="mb-4">
            <ul class="space-y-2 text-sm">
                <li v-for="(active, key) in integrations" :key="key" class="flex items-center justify-between">
                    <code class="text-xs">{{ key }}</code>
                    <Badge :color="active ? 'green' : 'gray'" :text="active ? __('Detected') : __('Not installed')" />
                </li>
            </ul>
        </Panel>

        <Panel :heading="__('License')" class="mb-4">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Mode') }}</dt>
                    <dd><code class="text-xs">{{ license.mode }}</code></dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Key set') }}</dt>
                    <dd><Badge :color="license.has_key ? 'green' : 'gray'" :text="license.has_key ? __('Yes') : __('No')" /></dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Validation') }}</dt>
                    <dd><Badge :color="license.is_valid ? 'green' : 'amber'" :text="license.is_valid ? __('Valid') : __('No active license')" /></dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase tracking-wider text-gray-500 mb-1">{{ __('Pro features') }}</dt>
                    <dd>
                        <code v-for="f in license.features" :key="f" class="text-xs mr-1">{{ f }}</code>
                    </dd>
                </div>
            </dl>
        </Panel>

        <Panel :heading="__('Redact keys')">
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                {{ __('Run logs replace the values of these keys with [REDACTED] before storing.') }}
            </p>
            <div class="flex flex-wrap gap-1">
                <Badge v-for="key in redact_keys" :key="key" color="gray" :text="key" />
            </div>
        </Panel>
    </div>
</template>
