<script setup lang="ts">
import { ChartBarIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/20/solid';
import { NoSymbolIcon, UserGroupIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import { FolderIcon } from '@heroicons/vue/16/solid';
import { computed, type ComputedRef, inject, ref } from 'vue';
import { useStorage } from '@vueuse/core';
import {
    formatReportingDuration,
    getDayJsInstance,
    getLocalizedDayJs,
} from '@/packages/ui/src/utils/time';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import ReportingTabNavbar from '@/Components/Common/Reporting/ReportingTabNavbar.vue';
import ReportingChart from '@/Components/Common/Reporting/ReportingChart.vue';
import ReportingFilterBadge from '@/Components/Common/Reporting/ReportingFilterBadge.vue';
import ReportingGroupBySelect from '@/Components/Common/Reporting/ReportingGroupBySelect.vue';
import ReportingExportButton from '@/Components/Common/Reporting/ReportingExportButton.vue';
import ReportingExportModal from '@/Components/Common/Reporting/ReportingExportModal.vue';
import MemberMultiselectDropdown from '@/Components/Common/Member/MemberMultiselectDropdown.vue';
import ProjectMultiselectDropdown from '@/Components/Common/Project/ProjectMultiselectDropdown.vue';
import DateRangePicker from '@/packages/ui/src/Input/DateRangePicker.vue';
import { SaveIcon } from 'lucide-vue-next';
import { SecondaryButton } from '@/packages/ui/src';
import ReportCreateModal from '@/Components/Common/Report/ReportCreateModal.vue';
import UpgradeModal from '@/Components/Common/UpgradeModal.vue';
import {
    api,
    type AggregatedTimeEntries,
    type AggregatedTimeEntriesQueryParams,
    type CreateReportBodyProperties,
    type Organization,
} from '@/packages/api/src';
import type { ExportFormat } from '@/types/reporting';
import { getCurrentMembershipId, getCurrentOrganizationId, getCurrentRole } from '@/utils/useUser';
import { useAggregatedTimeEntriesQuery } from '@/utils/useAggregatedTimeEntriesQuery';
import { type GroupingOption, useReportingStore } from '@/utils/useReporting';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useNotificationsStore } from '@/utils/notification';
import { canCreateReports } from '@/utils/permissions';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';

const organization = inject<ComputedRef<Organization>>('organization');

const reportingStore = useReportingStore();
const { groupByOptions, getNameForReportingRowEntry, emptyPlaceholder } = reportingStore;
const { projects } = useProjectsQuery();

// --- Week selection (Current / Previous / Custom) -------------------------
function now() {
    return getLocalizedDayJs(getDayJsInstance()().format());
}

// Initialise to the current week.
const startDate = ref<string>(now().startOf('week').format());
const endDate = ref<string>(now().startOf('week').add(6, 'day').endOf('day').format());

function setWeekFromStart(weekStart: ReturnType<typeof now>) {
    startDate.value = weekStart.startOf('week').format();
    endDate.value = weekStart.startOf('week').add(6, 'day').endOf('day').format();
}

function setCurrentWeek() {
    setWeekFromStart(now());
}

function setPreviousWeek() {
    setWeekFromStart(now().subtract(1, 'week'));
}

function stepWeek(delta: number) {
    setWeekFromStart(getLocalizedDayJs(startDate.value).add(delta, 'week'));
}

const showCustomPicker = ref(false);

const selectedWeekStart = computed(() =>
    getLocalizedDayJs(startDate.value).startOf('week').format('YYYY-MM-DD')
);
const currentWeekStart = computed(() => now().startOf('week').format('YYYY-MM-DD'));
const previousWeekStart = computed(() =>
    now().subtract(1, 'week').startOf('week').format('YYYY-MM-DD')
);

// A range is a "clean" single week if it starts on a week boundary and spans 7 days.
const isSingleWeek = computed(() => {
    const start = getLocalizedDayJs(startDate.value);
    const end = getLocalizedDayJs(endDate.value);
    return (
        start.format('YYYY-MM-DD') === selectedWeekStart.value &&
        end.startOf('day').diff(start.startOf('day'), 'day') === 6
    );
});

const weekMode = computed<'current' | 'previous' | 'custom'>(() => {
    if (isSingleWeek.value && selectedWeekStart.value === currentWeekStart.value) {
        return 'current';
    }
    if (isSingleWeek.value && selectedWeekStart.value === previousWeekStart.value) {
        return 'previous';
    }
    return 'custom';
});

const weekRangeLabel = computed(() => {
    const start = getLocalizedDayJs(startDate.value);
    const end = getLocalizedDayJs(endDate.value);
    return `${start.format('MMM D')} – ${end.format('MMM D, YYYY')}`;
});

// --- Filters (User / Project / Team) --------------------------------------
const selectedMembers = ref<string[]>([]);
const selectedProjects = ref<string[]>([]);
const excludedProjects = ref<string[]>([]);

const hasActiveFilters = computed(
    () =>
        selectedMembers.value.length > 0 ||
        selectedProjects.value.length > 0 ||
        excludedProjects.value.length > 0
);
function clearFilters() {
    selectedMembers.value = [];
    selectedProjects.value = [];
    excludedProjects.value = [];
}

// --- "Group by" (rows of the matrix) --------------------------------------
const groupBy = useStorage<GroupingOption>('weekly-reporting-group', 'project');

// Resolve the effective include-list once exclusions are applied. The API only
// supports an include filter (project_ids), so "exclude" is expressed as
// (selected ── or all ── projects) minus the excluded ones.
const effectiveProjectIds = computed<string[] | undefined>(() => {
    if (excludedProjects.value.length === 0) {
        return selectedProjects.value.length > 0 ? selectedProjects.value : undefined;
    }
    const base =
        selectedProjects.value.length > 0
            ? selectedProjects.value
            : projects.value.map((p) => p.id);
    const filtered = base.filter((id) => !excludedProjects.value.includes(id));
    // Nothing left → force an empty result instead of "all".
    return filtered.length > 0 ? filtered : ['00000000-0000-0000-0000-000000000000'];
});

// --- Queries --------------------------------------------------------------
const baseParams = computed<AggregatedTimeEntriesQueryParams>(() => {
    return {
        start: getLocalizedDayJs(startDate.value).startOf('day').utc().format(),
        end: getLocalizedDayJs(endDate.value).endOf('day').utc().format(),
        member_ids: selectedMembers.value.length > 0 ? selectedMembers.value : undefined,
        project_ids: effectiveProjectIds.value,
        // Employees are restricted to their own time entries.
        member_id: getCurrentRole() === 'employee' ? getCurrentMembershipId() : undefined,
    };
});

// Cards: split billable vs non-billable in a single grouped query.
const cardsParams = computed<AggregatedTimeEntriesQueryParams>(() => ({
    ...baseParams.value,
    group: 'billable',
}));
// Daily summary chart.
const dailyParams = computed<AggregatedTimeEntriesQueryParams>(() => ({
    ...baseParams.value,
    group: 'day',
    fill_gaps_in_time_groups: 'true',
}));
// Matrix: selected grouping (rows) split per day (columns).
const matrixParams = computed<AggregatedTimeEntriesQueryParams>(() => ({
    ...baseParams.value,
    group: groupBy.value,
    sub_group: 'day',
    fill_gaps_in_time_groups: 'true',
}));

const { data: cardsResponse } = useAggregatedTimeEntriesQuery('weekly-cards', cardsParams);
const { data: dailyResponse } = useAggregatedTimeEntriesQuery('weekly-daily', dailyParams);
const { data: matrixResponse } = useAggregatedTimeEntriesQuery('weekly-matrix', matrixParams);

const cardsData = computed<AggregatedTimeEntries | undefined>(
    () => cardsResponse.value?.data as AggregatedTimeEntries | undefined
);
const dailyData = computed<AggregatedTimeEntries | undefined>(
    () => dailyResponse.value?.data as AggregatedTimeEntries | undefined
);
const matrixData = computed<AggregatedTimeEntries | undefined>(
    () => matrixResponse.value?.data as AggregatedTimeEntries | undefined
);

// Totals for the summary cards.
const totalSeconds = computed(() => cardsData.value?.seconds ?? 0);
const nonBillableSeconds = computed(() =>
    (cardsData.value?.grouped_data ?? [])
        .filter((entry) => String(entry.key) === '0' || String(entry.key) === 'false')
        .reduce((sum, entry) => sum + entry.seconds, 0)
);
const billableSeconds = computed(() => totalSeconds.value - nonBillableSeconds.value);

function formatHours(seconds: number) {
    return formatReportingDuration(
        seconds,
        organization?.value?.interval_format,
        organization?.value?.number_format
    );
}

const summaryCards = computed(() => [
    { label: 'Total Hours', seconds: totalSeconds.value },
    { label: 'Billable Hours', seconds: billableSeconds.value },
    { label: 'Non-Billable Hours', seconds: nonBillableSeconds.value },
]);

// --- Matrix (Clockify-style: rows = group, columns = days of week) --------
const dayColumns = computed(() => {
    const cols: { key: string; weekday: string; date: string }[] = [];
    let day = getLocalizedDayJs(startDate.value).startOf('day');
    const end = getLocalizedDayJs(endDate.value).startOf('day');
    let guard = 0;
    while (!day.isAfter(end) && guard < 400) {
        cols.push({
            key: day.format('YYYY-MM-DD'),
            weekday: day.format('ddd'),
            date: day.format('MMM D'),
        });
        day = day.add(1, 'day');
        guard++;
    }
    return cols;
});

const projectColorMap = computed(() => {
    const map: Record<string, string | null> = {};
    for (const project of projects.value) {
        map[project.id] = project.color ?? null;
    }
    return map;
});

function colorForRow(key: string | null): string | null {
    if (groupBy.value === 'project' && key !== null) {
        return projectColorMap.value[key] ?? null;
    }
    return null;
}

const matrixRows = computed(() => {
    const groupedType = matrixData.value?.grouped_type ?? null;
    return (matrixData.value?.grouped_data ?? []).map((entry) => {
        const perDay: Record<string, number> = {};
        for (const sub of entry.grouped_data ?? []) {
            if (sub.key !== null) {
                perDay[sub.key] = sub.seconds;
            }
        }
        const name =
            getNameForReportingRowEntry(entry.key, groupedType) ??
            emptyPlaceholder[groupBy.value] ??
            '—';
        return {
            key: entry.key ?? 'none',
            name,
            color: colorForRow(entry.key),
            perDay,
            total: entry.seconds,
        };
    });
});

const columnTotals = computed(() =>
    dayColumns.value.map((col) =>
        matrixRows.value.reduce((sum, row) => sum + (row.perDay[col.key] ?? 0), 0)
    )
);

const grandTotal = computed(() => matrixData.value?.seconds ?? 0);

const gridTemplate = computed(
    () => `minmax(180px, 1.5fr) repeat(${dayColumns.value.length}, minmax(80px, 1fr)) 120px`
);

// --- Export (PDF / Excel / CSV / ODS) -------------------------------------
const { handleApiRequestNotifications } = useNotificationsStore();
const showExportModal = ref(false);
const exportUrl = ref<string | null>(null);

async function downloadExport(format: ExportFormat) {
    const organizationId = getCurrentOrganizationId();
    if (!organizationId) {
        return;
    }
    const response = await handleApiRequestNotifications(
        () =>
            api.exportAggregatedTimeEntries({
                params: { organization: organizationId },
                queries: {
                    ...baseParams.value,
                    group: groupBy.value,
                    sub_group: 'day',
                    history_group: 'day',
                    format,
                },
            }),
        'Export successful',
        'Export failed'
    );
    if (response?.download_url) {
        exportUrl.value = response.download_url as string;
        showExportModal.value = true;
    }
}

// --- Save report ----------------------------------------------------------
const showCreateReportModal = ref(false);
const showPremiumModal = ref(false);

const reportProperties = computed(
    () =>
        ({
            ...baseParams.value,
            billable: null,
            group: groupBy.value,
            sub_group: 'day',
            history_group: 'day',
            format: 'weekly',
        }) as CreateReportBodyProperties
);

function onSaveReportClick() {
    if (isAllowedToPerformPremiumAction()) {
        showCreateReportModal.value = true;
    } else {
        showPremiumModal.value = true;
    }
}
</script>

<template>
    <MainContainer
        class="h-14 sm:h-16 border-b border-default-background-separator flex flex-wrap gap-y-3 justify-between items-center">
        <div class="flex items-center space-x-3 sm:space-x-6">
            <PageTitle :icon="ChartBarIcon" title="Weekly Report"></PageTitle>
            <ReportingTabNavbar active="weekly" class="hidden sm:flex"></ReportingTabNavbar>
        </div>
        <SecondaryButton v-if="canCreateReports()" :icon="SaveIcon" @click="onSaveReportClick">
            Save Report
        </SecondaryButton>
    </MainContainer>
    <MainContainer class="sm:hidden py-2 border-b border-default-background-separator">
        <ReportingTabNavbar active="weekly"></ReportingTabNavbar>
    </MainContainer>

    <!-- Week selector + filters -->
    <div class="py-2.5 w-full border-b border-default-background-separator">
        <MainContainer class="sm:flex space-y-4 sm:space-y-0 justify-between items-center">
            <div class="flex flex-wrap items-center gap-3">
                <div
                    class="flex items-center rounded-lg border border-input-border bg-input-background overflow-hidden">
                    <button
                        type="button"
                        class="px-2 py-1.5 text-text-secondary hover:text-text-primary hover:bg-secondary transition border-r border-input-border"
                        aria-label="Previous week"
                        @click="stepWeek(-1)">
                        <ChevronLeftIcon class="w-4 h-4" />
                    </button>
                    <span
                        class="px-3 py-1.5 text-sm text-text-primary font-medium whitespace-nowrap">
                        {{ weekRangeLabel }}
                    </span>
                    <button
                        type="button"
                        class="px-2 py-1.5 text-text-secondary hover:text-text-primary hover:bg-secondary transition border-l border-input-border"
                        aria-label="Next week"
                        @click="stepWeek(1)">
                        <ChevronRightIcon class="w-4 h-4" />
                    </button>
                </div>
                <SecondaryButton
                    size="small"
                    :class="weekMode === 'current' ? 'ring-2 ring-accent-300/50' : ''"
                    @click="setCurrentWeek">
                    Current Week
                </SecondaryButton>
                <SecondaryButton
                    size="small"
                    :class="weekMode === 'previous' ? 'ring-2 ring-accent-300/50' : ''"
                    @click="setPreviousWeek">
                    Previous Week
                </SecondaryButton>
                <SecondaryButton
                    size="small"
                    :class="
                        weekMode === 'custom' || showCustomPicker ? 'ring-2 ring-accent-300/50' : ''
                    "
                    @click="showCustomPicker = !showCustomPicker">
                    Custom
                </SecondaryButton>
                <div v-if="showCustomPicker" class="w-[260px] max-w-full">
                    <DateRangePicker v-model:start="startDate" v-model:end="endDate" />
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="text-sm font-medium">Filters</div>
                <MemberMultiselectDropdown v-model="selectedMembers">
                    <template #trigger>
                        <ReportingFilterBadge
                            :count="selectedMembers.length"
                            :active="selectedMembers.length > 0"
                            title="Users"
                            :icon="UserGroupIcon" />
                    </template>
                </MemberMultiselectDropdown>
                <ProjectMultiselectDropdown v-model="selectedProjects">
                    <template #trigger>
                        <ReportingFilterBadge
                            :count="selectedProjects.length"
                            :active="selectedProjects.length > 0"
                            title="Projects"
                            :icon="FolderIcon" />
                    </template>
                </ProjectMultiselectDropdown>
                <ProjectMultiselectDropdown v-model="excludedProjects">
                    <template #trigger>
                        <ReportingFilterBadge
                            :count="excludedProjects.length"
                            :active="excludedProjects.length > 0"
                            title="Exclude"
                            :icon="NoSymbolIcon" />
                    </template>
                </ProjectMultiselectDropdown>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="flex items-center gap-1 text-sm text-text-secondary hover:text-text-primary transition px-2 py-1.5"
                    @click="clearFilters">
                    <XMarkIcon class="w-4 h-4" />
                    <span>Clear filters</span>
                </button>
            </div>
        </MainContainer>
    </div>

    <!-- Summary cards -->
    <MainContainer>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-6">
            <div
                v-for="card in summaryCards"
                :key="card.label"
                class="bg-secondary rounded-lg border border-card-border px-6 py-5">
                <div class="text-sm text-text-tertiary">{{ card.label }}</div>
                <div class="text-2xl font-semibold text-text-primary pt-1">
                    {{ formatHours(card.seconds) }}
                </div>
            </div>
        </div>
    </MainContainer>

    <!-- Daily chart -->
    <MainContainer>
        <div class="pt-10 w-full px-3 relative">
            <ReportingChart
                :grouped-type="dailyData?.grouped_type ?? null"
                :grouped-data="dailyData?.grouped_data ?? null"></ReportingChart>
        </div>
    </MainContainer>

    <!-- Weekly matrix: rows = group, columns = days -->
    <MainContainer>
        <div class="pt-10 pb-10">
            <div class="bg-secondary rounded-lg border border-card-border">
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-text-primary font-medium px-6 py-3 border-b border-card-background-separator">
                    <span>Group by</span>
                    <ReportingGroupBySelect
                        v-model="groupBy"
                        :group-by-options="groupByOptions"></ReportingGroupBySelect>
                    <span class="ml-auto text-text-tertiary">
                        Total: {{ formatHours(grandTotal) }}
                    </span>
                    <ReportingExportButton :download="downloadExport"></ReportingExportButton>
                </div>

                <div class="overflow-x-auto">
                    <div class="min-w-max">
                        <!-- Header row -->
                        <div
                            class="grid items-center text-text-tertiary text-sm border-b border-card-background-separator"
                            :style="`grid-template-columns: ${gridTemplate}`">
                            <div class="pl-6 py-2 font-medium uppercase tracking-wide text-xs">
                                {{ groupByOptions.find((o) => o.value === groupBy)?.label }}
                            </div>
                            <div
                                v-for="col in dayColumns"
                                :key="col.key"
                                class="py-2 px-2 text-right leading-tight">
                                <div class="font-medium text-text-secondary">{{ col.weekday }}</div>
                                <div class="text-xs">{{ col.date }}</div>
                            </div>
                            <div class="pr-6 py-2 text-right font-medium">Total</div>
                        </div>

                        <!-- Data rows -->
                        <template v-if="matrixRows.length > 0">
                            <div
                                v-for="row in matrixRows"
                                :key="row.key"
                                class="grid items-center border-b border-card-background-separator hover:bg-tertiary transition"
                                :style="`grid-template-columns: ${gridTemplate}`">
                                <div class="pl-6 py-2.5 flex items-center gap-2 min-w-0">
                                    <span
                                        class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                        :style="`background-color: ${row.color ?? '#9ca3af'}`"></span>
                                    <span class="text-text-primary truncate">{{ row.name }}</span>
                                </div>
                                <div
                                    v-for="col in dayColumns"
                                    :key="col.key"
                                    class="py-2.5 px-2 text-right tabular-nums"
                                    :class="
                                        row.perDay[col.key]
                                            ? 'text-text-primary'
                                            : 'text-text-quaternary'
                                    ">
                                    {{
                                        row.perDay[col.key]
                                            ? formatHours(row.perDay[col.key] ?? 0)
                                            : '–'
                                    }}
                                </div>
                                <div
                                    class="pr-6 py-2.5 text-right font-medium text-text-primary tabular-nums">
                                    {{ formatHours(row.total) }}
                                </div>
                            </div>

                            <!-- Totals row -->
                            <div
                                class="grid items-center text-text-secondary font-medium"
                                :style="`grid-template-columns: ${gridTemplate}`">
                                <div class="pl-6 py-3">Total</div>
                                <div
                                    v-for="(total, index) in columnTotals"
                                    :key="'total-' + index"
                                    class="py-3 px-2 text-right tabular-nums"
                                    :class="total ? '' : 'text-text-quaternary'">
                                    {{ total ? formatHours(total) : '–' }}
                                </div>
                                <div class="pr-6 py-3 text-right text-text-primary tabular-nums">
                                    {{ formatHours(grandTotal) }}
                                </div>
                            </div>
                        </template>

                        <div
                            v-else
                            class="flex flex-col items-center justify-center py-16 text-text-tertiary">
                            <p class="text-lg text-text-primary font-medium">
                                No time entries found
                            </p>
                            <p>Try to change the filters or week</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainContainer>

    <ReportingExportModal
        v-model:show="showExportModal"
        :export-url="exportUrl"></ReportingExportModal>
    <ReportCreateModal
        v-model:show="showCreateReportModal"
        :properties="reportProperties"></ReportCreateModal>
    <UpgradeModal v-model:show="showPremiumModal">
        Saved &amp; shareable reports are only available in solidtime Professional.
    </UpgradeModal>
</template>

<style scoped></style>
