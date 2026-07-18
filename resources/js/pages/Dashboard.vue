<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import { Header, Button, Listing, Widget, Panel } from '@statamic/cms/ui';

const props = defineProps({
    title: { type: String, required: true },
    stats: { type: Object, required: true },
    trend: { type: Array, required: true },
    recentFailures: { type: Array, required: true },
    failureColumns: { type: Array, required: true },
    createUrl: { type: String, required: true },
    automationsUrl: { type: String, required: true },
    runsUrl: { type: String, required: true },
    canCreate: { type: Boolean, default: false },
});

const cards = computed(() => [
    { label: __('Automations'), value: props.stats.automations, href: props.automationsUrl },
    { label: __('Enabled'), value: props.stats.enabled, href: props.automationsUrl },
    { label: __('Runs (30d)'), value: props.stats.runs_30d, href: props.runsUrl },
    {
        label: __('Success rate (30d)'),
        value: props.stats.success_rate === null ? '—' : props.stats.success_rate + '%',
    },
    { label: __('Failed (30d)'), value: props.stats.failed_30d, href: props.runsUrl, tone: 'danger' },
]);

const maxTrend = computed(() => Math.max(1, ...props.trend.map((d) => d.total)));

function barHeight(value) {
    return Math.round((value / maxTrend.value) * 100) + '%';
}
</script>

<template>
    <Head :title="[title, __('Statamic Automations')]" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Header :title="title" icon="workflow">
            <Button v-if="canCreate" :href="createUrl" :text="__('Create automation')" variant="primary" />
        </Header>

        <!-- KPI cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
            <component
                :is="card.href ? Link : 'div'"
                v-for="card in cards"
                :key="card.label"
                :href="card.href"
                class="block"
            >
                <Widget :title="card.label" class="h-full">
                    <div
                        class="text-2xl font-semibold tabular-nums"
                        :class="card.tone === 'danger' && card.value ? 'text-red-600 dark:text-red-400' : ''"
                    >
                        {{ card.value }}
                    </div>
                </Widget>
            </component>
        </div>

        <!-- 14-day trend -->
        <Panel :heading="__('Runs — last 14 days')" class="mb-6">
            <div class="p-4">
                <div class="flex items-end gap-1">
                    <div v-for="day in trend" :key="day.date" class="flex-1 flex flex-col items-center gap-1" :title="`${day.date}: ${day.total} run(s)`">
                        <div class="w-full h-32 flex flex-col justify-end overflow-hidden rounded-t-sm bg-gray-100 dark:bg-gray-800">
                            <div class="w-full bg-red-400 dark:bg-red-500" :style="{ height: barHeight(day.failed) }"></div>
                            <div class="w-full bg-green-500" :style="{ height: barHeight(day.success) }"></div>
                        </div>
                        <div class="text-[10px] text-gray-400">{{ day.date.slice(5) }}</div>
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-green-500"></span>{{ __('Success') }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-400 dark:bg-red-500"></span>{{ __('Failed') }}</span>
                </div>
            </div>
        </Panel>

        <!-- Recent failures -->
        <Panel :heading="__('Recent failures')">
            <div
                v-if="recentFailures.length === 0"
                class="p-6 text-center text-sm text-gray-500 dark:text-gray-400"
            >
                {{ __('No failed runs.') }}
            </div>
            <Listing
                v-else
                :items="recentFailures"
                :columns="failureColumns"
                preferences-prefix="statamic-automations.dashboard-failures"
            >
                <template #cell-trigger_type="{ row }">
                    <Link :href="row.show_url" class="font-medium">{{ row.trigger_type }}</Link>
                </template>
                <template #cell-error_message="{ row }">
                    <span class="text-2xs text-red-600 dark:text-red-400">{{ row.error_message }}</span>
                </template>
                <template #cell-failed_at="{ row }">
                    <span class="text-2xs text-gray-500">{{ row.failed_at ? new Date(row.failed_at).toLocaleString() : '—' }}</span>
                </template>
            </Listing>
        </Panel>
    </div>
</template>
