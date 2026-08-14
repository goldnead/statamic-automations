<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import { Header, Button, Listing, Card, Panel, Heading, Subheading } from '@statamic/cms/ui';

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

        <!-- Stat tiles. `Card` + `Subheading` + `Heading`, the same three parts
             every other dashboard in this addon family is built from — the
             `Widget` used here before is a dashboard-widget chrome with its own
             header rule and minimum height, which left each number stranded in
             the top-left corner of a tall empty box. -->
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
            <component
                :is="card.href ? Link : 'div'"
                v-for="card in cards"
                :key="card.label"
                :href="card.href"
                class="block"
            >
                <Card class="h-full">
                    <Subheading :text="card.label" />
                    <Heading
                        size="2xl"
                        class="mt-2 tabular-nums"
                        :class="card.tone === 'danger' && card.value ? 'text-red-600 dark:text-red-400' : ''"
                        :text="String(card.value)"
                    />
                </Card>
            </component>
        </div>

        <!-- 14-day trend. The chart sits on a `Card` inside the `Panel`: a Panel
             is a headed region, not a surface, so its bare body took the page
             background and the chart read as a grey slab next to the white
             tiles above it. -->
        <Panel :heading="__('Runs — last 14 days')" class="mb-6">
            <Card>
                <!-- Hand-drawn because core ships no chart component. The bars
                     carry no text, so the whole thing is announced as a list
                     with one labelled row per day; without this a screen reader
                     reads an empty group. -->
                <div
                    class="flex items-end gap-1"
                    role="list"
                    :aria-label="__('Runs — last 14 days')"
                >
                    <div
                        v-for="day in trend"
                        :key="day.date"
                        role="listitem"
                        class="flex-1 flex flex-col items-center gap-1"
                        :title="`${day.date}: ${day.total} run(s)`"
                        :aria-label="__(':date: :success succeeded, :failed failed', { date: day.date, success: day.success, failed: day.failed })"
                    >
                        <div class="w-full h-32 flex flex-col justify-end overflow-hidden rounded-t-sm bg-gray-100 dark:bg-gray-800" aria-hidden="true">
                            <div class="w-full bg-red-400 dark:bg-red-500" :style="{ height: barHeight(day.failed) }"></div>
                            <div class="w-full bg-green-500" :style="{ height: barHeight(day.success) }"></div>
                        </div>
                        <div class="text-[10px] text-gray-400" aria-hidden="true">{{ day.date.slice(5) }}</div>
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-3 text-xs text-gray-500 dark:text-gray-400">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-green-500"></span>{{ __('Success') }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-red-400 dark:bg-red-500"></span>{{ __('Failed') }}</span>
                </div>
            </Card>
        </Panel>

        <!-- Recent failures — same reason for the Card as the trend above. -->
        <Panel :heading="__('Recent failures')">
            <Card v-if="recentFailures.length === 0">
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No failed runs.') }}
                </p>
            </Card>
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
