<script setup lang="ts">
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import { ChartBarIcon } from '@heroicons/vue/20/solid';
import ReportingChart from '@/Components/Common/Reporting/ReportingChart.vue';
import {
    formatReportingDuration,
    getDayJsInstance,
    getLocalizedDayJs,
} from '@/packages/ui/src/utils/time';
import {
    buildRefMaps,
    computeAttendance,
    computeDetailedTree,
    dayColumnsBetween,
    fmtDuration as utilFmtDuration,
    fmtDurationZero,
    type RawEntry,
    type RawReferences,
} from '@/utils/sharedReportLayouts';
import ReportingRow from '@/Components/Common/Reporting/ReportingRow.vue';
import ReportingPieChart from '@/Components/Common/Reporting/ReportingPieChart.vue';
import { formatCents } from '@/packages/ui/src/utils/money';
import type { CurrencyFormat } from '@/packages/ui/src/utils/money';
import { computed, onMounted, provide, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/packages/api/src';
import { getRandomColorWithSeed } from '@/packages/ui/src/utils/color';
import { useReportingStore } from '@/utils/useReporting';
import { Head } from '@inertiajs/vue3';
import { useTheme } from '@/utils/theme';

const sharedSecret = ref<string | null>(null);

const hasSharedSecret = computed(() => {
    return sharedSecret.value !== null;
});

const { data: sharedReportResponseData } = useQuery({
    enabled: hasSharedSecret,
    queryKey: ['reporting', sharedSecret],
    queryFn: () =>
        api.getPublicReport({
            headers: {
                'X-Api-Key': sharedSecret.value,
            },
        }),
});

onMounted(() => {
    const currentUrl = window.location.href;
    // check if # exists exactly once in the URL
    if (currentUrl.split('#').length === 2) {
        sharedSecret.value = currentUrl.split('#')[1] ?? null;
    }
});

const reportCurrency = computed(() => {
    if (sharedReportResponseData.value) {
        return sharedReportResponseData.value?.currency;
    }
    return 'EUR';
});

const reportIntervalFormat = computed(() => {
    return sharedReportResponseData.value?.interval_format;
});

const reportNumberFormat = computed(() => {
    return sharedReportResponseData.value?.number_format;
});

const reportCurrencyFormat = computed(() => {
    return (sharedReportResponseData.value?.currency_format ?? 'symbol-before') as CurrencyFormat;
});

const reportDateFormat = computed(() => {
    return sharedReportResponseData.value?.date_format;
});

const reportCurrencySymbol = computed(() => {
    return sharedReportResponseData.value?.currency_symbol;
});

provide(
    'organization',
    computed(() => ({
        'number_format': reportNumberFormat.value,
        'interval_format': reportIntervalFormat.value,
        'currency_format': reportCurrencyFormat.value,
        'currency_symbol': reportCurrencySymbol.value,
        'date_format': reportDateFormat.value,
    }))
);

const aggregatedTableTimeEntries = computed(() => {
    if (sharedReportResponseData.value) {
        return sharedReportResponseData.value?.data;
    }
    return {
        grouped_data: [],
        grouped_type: 'project',
        seconds: 0,
        cost: 0,
    };
});
const aggregatedGraphTimeEntries = computed(() => {
    if (sharedReportResponseData.value) {
        return sharedReportResponseData.value?.history_data;
    }
    // Placeholder Data
    return {
        grouped_data: [],
        grouped_type: 'project',
        seconds: 0,
        cost: 0,
    };
});

const group = computed(() => {
    if (sharedReportResponseData.value) {
        return sharedReportResponseData.value?.properties.group;
    }
    return 'billable';
});

const subGroup = computed(() => {
    if (sharedReportResponseData.value) {
        return sharedReportResponseData.value?.properties.sub_group;
    }
    return 'project';
});
const { emptyPlaceholder } = useReportingStore();

// --- Weekly matrix layout (when the saved report's format is 'weekly') -----
const reportFormat = computed<string | null>(
    () => (sharedReportResponseData.value?.properties as { format?: string } | undefined)?.format ?? null
);
const isWeeklyFormat = computed(() => reportFormat.value === 'weekly');

function fmtDuration(seconds: number) {
    return seconds
        ? formatReportingDuration(seconds, reportIntervalFormat.value, reportNumberFormat.value)
        : '';
}

const dayColumns = computed(() => {
    const set = new Set<string>();
    for (const g1 of aggregatedTableTimeEntries.value?.grouped_data ?? []) {
        for (const g2 of (g1 as { grouped_data?: { key?: string | null }[] }).grouped_data ?? []) {
            if (g2.key) {
                set.add(g2.key);
            }
        }
    }
    return Array.from(set)
        .sort()
        .map((key) => ({ key, label: getLocalizedDayJs(key).format('ddd, MMM D') }));
});

const matrixRows = computed(() =>
    (aggregatedTableTimeEntries.value?.grouped_data ?? []).map((g1) => {
        const row = g1 as {
            description: string | null;
            color: string | null;
            seconds: number;
            key: string | null;
            grouped_data?: { key?: string | null; seconds: number }[];
        };
        const perDay: Record<string, number> = {};
        for (const g2 of row.grouped_data ?? []) {
            if (g2.key) {
                perDay[g2.key] = g2.seconds;
            }
        }
        return {
            key: row.key ?? row.description ?? 'none',
            name:
                row.description ??
                emptyPlaceholder[aggregatedTableTimeEntries.value?.grouped_type ?? 'project'] ??
                '—',
            color: row.color ?? getRandomColorWithSeed(row.description ?? 'none'),
            perDay,
            total: row.seconds,
        };
    })
);

const matrixColumnTotals = computed(() =>
    dayColumns.value.map((col) =>
        matrixRows.value.reduce((sum, r) => sum + (r.perDay[col.key] ?? 0), 0)
    )
);
const matrixGrandTotal = computed(() => aggregatedTableTimeEntries.value?.seconds ?? 0);
const matrixGridTemplate = computed(
    () => `minmax(160px, 1.5fr) repeat(${dayColumns.value.length}, minmax(80px, 1fr)) 120px`
);

// --- Raw-entry based layouts (weekly-detailed tree, attendance metrics) ----
const isWeeklyDetailedFormat = computed(() => reportFormat.value === 'weekly-detailed');
const isAttendanceFormat = computed(() => reportFormat.value === 'attendance');

const rawEntries = computed<RawEntry[]>(
    () => (sharedReportResponseData.value as { entries?: RawEntry[] } | undefined)?.entries ?? []
);
const refMaps = computed(() =>
    buildRefMaps(
        (sharedReportResponseData.value as { references?: RawReferences } | undefined)?.references ??
            null
    )
);
const formatConfig = computed<{ excludeProjectIds?: string[]; groupBy?: string }>(
    () =>
        (sharedReportResponseData.value?.properties as { format_config?: Record<string, unknown> } | undefined)
            ?.format_config ?? {}
);
const reportStart = computed(
    () => sharedReportResponseData.value?.properties.start ?? getDayJsInstance()().format()
);
const reportEnd = computed(
    () => sharedReportResponseData.value?.properties.end ?? getDayJsInstance()().format()
);

// Weekly-detailed tree (rows expand Project -> Task -> Description).
const detailedDayColumns = computed(() => dayColumnsBetween(reportStart.value, reportEnd.value));
const detailedTree = computed(() => computeDetailedTree(rawEntries.value, refMaps.value));
const detailedExpanded = ref<Set<string>>(new Set());
function toggleDetailed(key: string) {
    const next = new Set(detailedExpanded.value);
    next.has(key) ? next.delete(key) : next.add(key);
    detailedExpanded.value = next;
}
const detailedVisibleRows = computed(() => {
    const rows: { level: 0 | 1 | 2; key: string; label: string; color: string | null; perDay: Record<string, number>; total: number; expandable: boolean; isOpen: boolean }[] = [];
    for (const project of detailedTree.value) {
        const pOpen = detailedExpanded.value.has(project.key);
        rows.push({ ...project, expandable: project.children.length > 0, isOpen: pOpen });
        if (!pOpen) continue;
        for (const task of project.children) {
            const tOpen = detailedExpanded.value.has(task.key);
            rows.push({ ...task, expandable: task.children.length > 0, isOpen: tOpen });
            if (!tOpen) continue;
            for (const desc of task.children) {
                rows.push({ ...desc, expandable: false, isOpen: false });
            }
        }
    }
    return rows;
});
const detailedColumnTotals = computed(() =>
    detailedDayColumns.value.map((c) => detailedTree.value.reduce((s, p) => s + (p.perDay[c.key] ?? 0), 0))
);
const detailedGrandTotal = computed(() => detailedTree.value.reduce((s, p) => s + p.total, 0));
const detailedGridTemplate = computed(
    () => `minmax(240px, 1.6fr) repeat(${detailedDayColumns.value.length}, minmax(78px, 1fr)) 110px`
);

// Attendance metrics + matrix.
const attendance = computed(() =>
    computeAttendance(
        rawEntries.value,
        refMaps.value,
        formatConfig.value.excludeProjectIds ?? [],
        (formatConfig.value.groupBy as 'project' | 'user' | 'task' | 'client') ?? 'project'
    )
);
const attendanceGridTemplate = computed(
    () => `minmax(160px, 1.5fr) repeat(${attendance.value.dayKeys.length}, minmax(80px, 1fr)) 110px`
);

const groupedPieChartData = computed(() => {
    return (
        aggregatedTableTimeEntries.value?.grouped_data?.map((entry) => {
            if (entry.description === null) {
                return {
                    value: entry.seconds,
                    name:
                        emptyPlaceholder[
                            aggregatedTableTimeEntries.value?.grouped_type ?? 'project'
                        ] ?? '',
                    color: '#CCCCCC',
                };
            }
            return {
                value: entry.seconds,
                name: entry.description,
                color: entry.color ?? getRandomColorWithSeed(entry.description ?? 'none'),
            };
        }) ?? []
    );
});

const tableData = computed(() => {
    return aggregatedTableTimeEntries.value?.grouped_data?.map((entry) => {
        return {
            seconds: entry.seconds,
            cost: entry.cost,
            description:
                entry.description ??
                emptyPlaceholder[aggregatedTableTimeEntries.value?.grouped_type ?? 'project'] ??
                '',
            grouped_data:
                entry.grouped_data?.map((el) => {
                    return {
                        seconds: el.seconds,
                        cost: el.cost,
                        description:
                            el.description ??
                            emptyPlaceholder[entry.grouped_type ?? 'project'] ??
                            '',
                    };
                }) ?? [],
        };
    });
});

const { groupByOptions } = useReportingStore();

function getGroupLabel(key: string) {
    return groupByOptions.find((option) => {
        return option.value === key;
    })?.label;
}

onMounted(async () => {
    useTheme();
});
</script>

<template>
    <Head :title="sharedReportResponseData?.name" />

    <div class="text-text-secondary">
        <MainContainer
            class="py-3 sm:py-5 border-b border-default-background-separator flex justify-between items-center">
            <div class="flex items-center space-x-3 sm:space-x-6">
                <PageTitle :icon="ChartBarIcon" title="Reporting"></PageTitle>
            </div>
        </MainContainer>
        <MainContainer v-if="!isWeeklyDetailedFormat && !isAttendanceFormat">
            <div class="pt-10 w-full px-3 relative">
                <ReportingChart
                    :grouped-type="aggregatedGraphTimeEntries?.grouped_type"
                    :grouped-data="aggregatedGraphTimeEntries?.grouped_data"></ReportingChart>
            </div>
        </MainContainer>
        <!-- Weekly matrix layout -->
        <MainContainer v-if="isWeeklyFormat">
            <div class="pt-6 pb-10">
                <div class="bg-card-background rounded-lg border border-card-border overflow-x-auto">
                    <div class="min-w-max">
                        <div
                            class="grid items-center border-b border-card-background-separator text-text-tertiary text-xs"
                            :style="`grid-template-columns: ${matrixGridTemplate}`">
                            <div class="pl-6 py-2 font-semibold uppercase">
                                {{ getGroupLabel(group) }}
                            </div>
                            <div
                                v-for="col in dayColumns"
                                :key="col.key"
                                class="py-2 px-2 text-right">
                                {{ col.label }}
                            </div>
                            <div class="pr-6 py-2 text-right font-medium">Total</div>
                        </div>
                        <div
                            v-for="row in matrixRows"
                            :key="row.key"
                            class="grid items-center border-b border-card-background-separator text-sm"
                            :style="`grid-template-columns: ${matrixGridTemplate}`">
                            <div class="pl-6 py-2 flex items-center gap-2 min-w-0">
                                <span
                                    class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                    :style="`background-color: ${row.color}`"></span>
                                <span class="truncate text-text-primary">{{ row.name }}</span>
                            </div>
                            <div
                                v-for="col in dayColumns"
                                :key="col.key"
                                class="py-2 px-2 text-right tabular-nums"
                                :class="row.perDay[col.key] ? 'text-text-primary' : 'text-text-quaternary'">
                                {{ row.perDay[col.key] ? fmtDuration(row.perDay[col.key] ?? 0) : '–' }}
                            </div>
                            <div class="pr-6 py-2 text-right font-medium tabular-nums text-text-primary">
                                {{ fmtDuration(row.total) || '0:00:00' }}
                            </div>
                        </div>
                        <div
                            class="grid items-center text-sm font-medium text-text-secondary"
                            :style="`grid-template-columns: ${matrixGridTemplate}`">
                            <div class="pl-6 py-2.5">Total</div>
                            <div
                                v-for="(total, index) in matrixColumnTotals"
                                :key="'t' + index"
                                class="py-2.5 px-2 text-right tabular-nums">
                                {{ total ? fmtDuration(total) : '–' }}
                            </div>
                            <div class="pr-6 py-2.5 text-right tabular-nums text-text-primary">
                                {{ fmtDuration(matrixGrandTotal) || '0:00:00' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </MainContainer>

        <!-- Weekly Detailed: expandable Project -> Task -> Description -->
        <MainContainer v-else-if="isWeeklyDetailedFormat">
            <div class="pt-6 pb-10">
                <div class="bg-card-background rounded-lg border border-card-border overflow-x-auto">
                    <div class="min-w-max">
                        <div
                            class="grid items-center border-b border-card-background-separator text-text-tertiary text-xs"
                            :style="`grid-template-columns: ${detailedGridTemplate}`">
                            <div class="pl-4 py-2 font-semibold uppercase">
                                Project / Task / Description
                            </div>
                            <div
                                v-for="col in detailedDayColumns"
                                :key="col.key"
                                class="py-2 px-2 text-right leading-tight">
                                <div class="font-medium">{{ col.weekday }}</div>
                                <div>{{ col.date }}</div>
                            </div>
                            <div class="pr-6 py-2 text-right font-medium">Total</div>
                        </div>
                        <div
                            v-for="row in detailedVisibleRows"
                            :key="row.key"
                            class="grid items-center border-b border-card-background-separator text-sm"
                            :style="`grid-template-columns: ${detailedGridTemplate}`">
                            <div
                                class="py-2 flex items-center gap-1.5 min-w-0"
                                :style="`padding-left: ${16 + row.level * 22}px`">
                                <button
                                    v-if="row.expandable"
                                    type="button"
                                    class="flex-shrink-0 text-text-tertiary hover:text-text-primary"
                                    @click="toggleDetailed(row.key)">
                                    <span class="inline-block w-3">{{ row.isOpen ? '▾' : '▸' }}</span>
                                </button>
                                <span v-else class="w-3 flex-shrink-0"></span>
                                <span
                                    v-if="row.level === 0"
                                    class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                    :style="`background-color: ${row.color ?? '#9ca3af'}`"></span>
                                <span
                                    class="truncate"
                                    :class="
                                        row.level === 0 ? 'text-text-primary font-medium' : ''
                                    ">
                                    {{ row.label }}
                                </span>
                            </div>
                            <div
                                v-for="col in detailedDayColumns"
                                :key="col.key"
                                class="py-2 px-2 text-right tabular-nums"
                                :class="row.perDay[col.key] ? 'text-text-primary' : 'text-text-quaternary'">
                                {{ row.perDay[col.key] ? utilFmtDuration(row.perDay[col.key] ?? 0) : '–' }}
                            </div>
                            <div class="pr-6 py-2 text-right font-medium tabular-nums">
                                {{ fmtDurationZero(row.total) }}
                            </div>
                        </div>
                        <div
                            class="grid items-center text-sm font-medium"
                            :style="`grid-template-columns: ${detailedGridTemplate}`">
                            <div class="pl-4 py-2.5">Total</div>
                            <div
                                v-for="(total, index) in detailedColumnTotals"
                                :key="'dt' + index"
                                class="py-2.5 px-2 text-right tabular-nums">
                                {{ total ? utilFmtDuration(total) : '–' }}
                            </div>
                            <div class="pr-6 py-2.5 text-right tabular-nums text-text-primary">
                                {{ fmtDurationZero(detailedGrandTotal) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </MainContainer>

        <!-- Attendance: daily metrics + group x day matrix -->
        <MainContainer v-else-if="isAttendanceFormat">
            <div class="pt-6 pb-10 space-y-8">
                <div class="bg-card-background rounded-lg border border-card-border overflow-x-auto">
                    <div class="min-w-max">
                        <div
                            class="grid border-b border-card-background-separator text-text-tertiary text-xs"
                            :style="`grid-template-columns: ${attendanceGridTemplate}`">
                            <div class="pl-6 py-2 font-semibold uppercase">Metric</div>
                            <div
                                v-for="k in attendance.dayKeys"
                                :key="k"
                                class="py-2 px-2 text-right font-medium">
                                {{ k }}
                            </div>
                            <div class="pr-6 py-2"></div>
                        </div>
                        <div
                            v-for="mrow in attendance.metricRows"
                            :key="mrow.label"
                            class="grid border-b border-card-background-separator text-sm"
                            :style="`grid-template-columns: ${attendanceGridTemplate}`">
                            <div class="pl-6 py-2 font-medium">{{ mrow.label }}</div>
                            <div
                                v-for="(val, i) in mrow.values"
                                :key="i"
                                class="py-2 px-2 text-right tabular-nums text-text-primary">
                                {{ val }}
                            </div>
                            <div class="pr-6 py-2"></div>
                        </div>
                    </div>
                </div>

                <div class="bg-card-background rounded-lg border border-card-border overflow-x-auto">
                    <div class="min-w-max">
                        <div
                            class="grid border-b border-card-background-separator text-text-tertiary text-xs"
                            :style="`grid-template-columns: ${attendanceGridTemplate}`">
                            <div class="pl-6 py-2 font-semibold uppercase">
                                {{ getGroupLabel(formatConfig.groupBy ?? 'project') }}
                            </div>
                            <div
                                v-for="k in attendance.dayKeys"
                                :key="k"
                                class="py-2 px-2 text-right font-medium">
                                {{ k }}
                            </div>
                            <div class="pr-6 py-2 text-right font-medium">Total</div>
                        </div>
                        <div
                            v-for="row in attendance.matrixRows"
                            :key="row.key"
                            class="grid items-center border-b border-card-background-separator text-sm"
                            :style="`grid-template-columns: ${attendanceGridTemplate}`">
                            <div class="pl-6 py-2 flex items-center gap-2 min-w-0">
                                <span
                                    class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                    :style="`background-color: ${row.color ?? '#9ca3af'}`"></span>
                                <span class="truncate text-text-primary">{{ row.label }}</span>
                            </div>
                            <div
                                v-for="k in attendance.dayKeys"
                                :key="k"
                                class="py-2 px-2 text-right tabular-nums"
                                :class="row.perDay[k] ? 'text-text-primary' : 'text-text-quaternary'">
                                {{ row.perDay[k] ? utilFmtDuration(row.perDay[k] ?? 0) : '–' }}
                            </div>
                            <div class="pr-6 py-2 text-right font-medium tabular-nums text-text-primary">
                                {{ fmtDurationZero(row.total) }}
                            </div>
                        </div>
                        <div
                            class="grid text-sm font-medium"
                            :style="`grid-template-columns: ${attendanceGridTemplate}`">
                            <div class="pl-6 py-2.5">Total</div>
                            <div
                                v-for="(total, index) in attendance.columnTotals"
                                :key="'at' + index"
                                class="py-2.5 px-2 text-right tabular-nums">
                                {{ total ? utilFmtDuration(total) : '–' }}
                            </div>
                            <div class="pr-6 py-2.5 text-right tabular-nums text-text-primary">
                                {{ fmtDurationZero(attendance.grandTotal) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </MainContainer>

        <!-- Default overview layout -->
        <MainContainer v-else>
            <div class="sm:grid grid-cols-4 pt-6 items-start">
                <div
                    class="col-span-3 bg-card-background rounded-lg border border-card-border pt-3">
                    <div
                        class="text-sm flex text-text-primary items-center font-medium px-6 border-b border-card-background-separator pb-3">
                        Group by
                        <strong class="px-2">{{ getGroupLabel(group) }}</strong>
                        and
                        <strong class="px-2">{{ getGroupLabel(subGroup) }}</strong>
                    </div>
                    <div class="grid items-center" style="grid-template-columns: 1fr 100px 150px">
                        <div
                            class="contents [&>*]:border-card-background-separator [&>*]:border-b [&>*]:bg-tertiary [&>*]:pb-1.5 [&>*]:pt-1 text-text-secondary text-sm">
                            <div class="pl-6">Name</div>
                            <div class="text-right">Duration</div>
                            <div class="text-right pr-6">Cost</div>
                        </div>
                        <template
                            v-if="
                                aggregatedTableTimeEntries?.grouped_data &&
                                aggregatedTableTimeEntries.grouped_data?.length > 0
                            ">
                            <ReportingRow
                                v-for="entry in tableData"
                                :key="entry.description ?? 'none'"
                                :currency="reportCurrency"
                                :currency-format="reportCurrencyFormat"
                                :show-cost="true"
                                :entry="entry"></ReportingRow>
                            <div
                                class="contents [&>*]:transition text-text-tertiary [&>*]:h-[50px]">
                                <div class="flex items-center pl-6 font-medium">
                                    <span>Total</span>
                                </div>
                                <div class="justify-end flex items-center font-medium">
                                    {{
                                        formatReportingDuration(
                                            aggregatedTableTimeEntries.seconds,
                                            reportIntervalFormat,
                                            reportNumberFormat
                                        )
                                    }}
                                </div>
                                <div class="justify-end pr-6 flex items-center font-medium">
                                    {{
                                        aggregatedTableTimeEntries.cost
                                            ? formatCents(
                                                  aggregatedTableTimeEntries.cost,
                                                  reportCurrency,
                                                  reportCurrencyFormat,
                                                  reportCurrencySymbol,
                                                  reportNumberFormat
                                              )
                                            : '--'
                                    }}
                                </div>
                            </div>
                        </template>
                        <div
                            v-else
                            class="chart flex flex-col items-center justify-center py-12 col-span-3">
                            <p class="text-lg text-text-primary font-semibold">
                                No time entries found
                            </p>
                            <p>Try to change the filters and time range</p>
                        </div>
                    </div>
                </div>
                <div class="px-2 lg:px-4">
                    <ReportingPieChart :data="groupedPieChartData"></ReportingPieChart>
                </div>
            </div>
        </MainContainer>
    </div>
</template>
