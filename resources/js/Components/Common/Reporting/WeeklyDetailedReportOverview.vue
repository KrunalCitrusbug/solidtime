<script setup lang="ts">
import {
    ChartBarIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    NoSymbolIcon,
    UserGroupIcon,
    XMarkIcon,
} from '@heroicons/vue/20/solid';
import { FolderIcon } from '@heroicons/vue/16/solid';
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import ReportingTabNavbar from '@/Components/Common/Reporting/ReportingTabNavbar.vue';
import ReportingExportButton from '@/Components/Common/Reporting/ReportingExportButton.vue';
import ReportingFilterBadge from '@/Components/Common/Reporting/ReportingFilterBadge.vue';
import MemberMultiselectDropdown from '@/Components/Common/Member/MemberMultiselectDropdown.vue';
import ProjectMultiselectDropdown from '@/Components/Common/Project/ProjectMultiselectDropdown.vue';
import DateRangePicker from '@/packages/ui/src/Input/DateRangePicker.vue';
import { SaveIcon } from 'lucide-vue-next';
import { SecondaryButton } from '@/packages/ui/src';
import ReportCreateModal from '@/Components/Common/Report/ReportCreateModal.vue';
import UpgradeModal from '@/Components/Common/UpgradeModal.vue';
import { api, type CreateReportBodyProperties } from '@/packages/api/src';
import type { ExportFormat } from '@/types/reporting';
import { getCurrentMembershipId, getCurrentOrganizationId, getCurrentRole } from '@/utils/useUser';
import { canCreateReports, canFilterReportsByMember, canViewOthersTimeEntries } from '@/utils/permissions';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';

const { projects } = useProjectsQuery();
const { clients } = useClientsQuery();
const { tasks } = useTasksQuery();

const isEmployee = computed(() => getCurrentRole() === 'employee');
const showMemberFilter = computed(() => canFilterReportsByMember());

// --- Week selection -------------------------------------------------------
function now() {
    return getLocalizedDayJs(getDayJsInstance()().format());
}
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

// --- Filters --------------------------------------------------------------
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

// --- Lookups --------------------------------------------------------------
const clientMap = computed(() => {
    const map: Record<string, string> = {};
    for (const c of clients.value) {
        map[c.id] = c.name;
    }
    return map;
});
const projectMap = computed(() => {
    const map: Record<string, { name: string; color: string | null; client: string | null }> = {};
    for (const p of projects.value) {
        map[p.id] = {
            name: p.name,
            color: p.color ?? null,
            client: p.client_id ? (clientMap.value[p.client_id] ?? null) : null,
        };
    }
    return map;
});
const taskMap = computed(() => {
    const map: Record<string, string> = {};
    for (const t of tasks.value) {
        map[t.id] = t.name;
    }
    return map;
});
function projectLabel(projectId: string | null): string {
    if (projectId === null) {
        return 'No Project';
    }
    const p = projectMap.value[projectId];
    if (!p) {
        return 'No Project';
    }
    return p.client ? `${p.client} - ${p.name}` : p.name;
}

// --- Data fetch -----------------------------------------------------------
type Entry = {
    start: string;
    end: string | null;
    duration: number | null;
    description: string | null;
    project_id: string | null;
    task_id: string | null;
};

const organizationId = computed(() => getCurrentOrganizationId());
const startUtc = computed(() => getLocalizedDayJs(startDate.value).startOf('day').utc().format());
const endUtc = computed(() => getLocalizedDayJs(endDate.value).endOf('day').utc().format());

async function fetchAllEntries(): Promise<Entry[]> {
    const orgId = organizationId.value;
    if (!orgId) {
        return [];
    }
    const limit = 500;
    let offset = 0;
    const all: Entry[] = [];
    for (let i = 0; i < 200; i++) {
        const res = await api.getTimeEntries({
            params: { organization: orgId },
            queries: {
                start: startUtc.value,
                end: endUtc.value,
                member_id: !canViewOthersTimeEntries()
                    ? (getCurrentMembershipId() ?? undefined)
                    : undefined,
                member_ids:
                    showMemberFilter.value && selectedMembers.value.length > 0
                        ? selectedMembers.value
                        : undefined,
                limit,
                offset,
            },
        });
        const page = (res.data ?? []) as Entry[];
        all.push(...page);
        const total = (res.meta as { total?: number } | undefined)?.total ?? all.length;
        if (page.length < limit || all.length >= total) {
            break;
        }
        offset += limit;
    }
    return all;
}

const { data: entries, isLoading } = useQuery({
    queryKey: computed(() => [
        'weeklyDetailedEntries',
        organizationId.value,
        startUtc.value,
        endUtc.value,
        selectedMembers.value,
    ]),
    queryFn: fetchAllEntries,
    enabled: computed(() => !!organizationId.value),
    placeholderData: (prev) => prev,
});

// --- Helpers --------------------------------------------------------------
function durationSeconds(entry: Entry): number {
    if (entry.duration !== null && entry.duration !== undefined) {
        return entry.duration;
    }
    const end = entry.end ? getLocalizedDayJs(entry.end) : now();
    return Math.max(0, end.diff(getLocalizedDayJs(entry.start), 'second'));
}
function dayKeyOf(iso: string): string {
    return getLocalizedDayJs(iso).format('YYYY-MM-DD');
}
function fmtDuration(seconds: number): string {
    if (!seconds) {
        return '';
    }
    const s = Math.round(seconds);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
}
function fmtDurationZero(seconds: number): string {
    return fmtDuration(seconds) || '0:00:00';
}

// --- Day columns ----------------------------------------------------------
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

// --- Tree: Project -> Task -> Description ----------------------------------
type Node = {
    key: string;
    label: string;
    perDay: Record<string, number>;
    total: number;
};
type DescNode = Node;
type TaskNode = Node & { children: Record<string, DescNode> };
type ProjectNode = Node & { color: string | null; children: Record<string, TaskNode> };

function addToPerDay(node: Node, dayKey: string, dur: number) {
    node.perDay[dayKey] = (node.perDay[dayKey] ?? 0) + dur;
    node.total += dur;
}

const includeSet = computed(() => new Set(selectedProjects.value));
const excludeSet = computed(() => new Set(excludedProjects.value));

const tree = computed<ProjectNode[]>(() => {
    const root: Record<string, ProjectNode> = {};
    for (const e of entries.value ?? []) {
        const pid = e.project_id;
        if (pid && excludeSet.value.has(pid)) {
            continue;
        }
        if (includeSet.value.size > 0 && !(pid && includeSet.value.has(pid))) {
            continue;
        }
        const dayKey = dayKeyOf(e.start);
        const dur = durationSeconds(e);

        const pKey = pid ?? '__none__';
        if (!root[pKey]) {
            root[pKey] = {
                key: pKey,
                label: projectLabel(pid),
                color: pid ? (projectMap.value[pid]?.color ?? null) : null,
                perDay: {},
                total: 0,
                children: {},
            };
        }
        const project = root[pKey];
        addToPerDay(project, dayKey, dur);

        const tKey = e.task_id ?? '__none__';
        if (!project.children[tKey]) {
            project.children[tKey] = {
                key: `${pKey}|${tKey}`,
                label: e.task_id ? (taskMap.value[e.task_id] ?? 'Unknown task') : 'No task',
                perDay: {},
                total: 0,
                children: {},
            };
        }
        const task = project.children[tKey];
        addToPerDay(task, dayKey, dur);

        const dRaw = e.description && e.description.trim() !== '' ? e.description.trim() : null;
        const dKey = dRaw ?? '__none__';
        if (!task.children[dKey]) {
            task.children[dKey] = {
                key: `${pKey}|${tKey}|${dKey}`,
                label: dRaw ?? 'No description',
                perDay: {},
                total: 0,
            };
        }
        addToPerDay(task.children[dKey], dayKey, dur);
    }
    const sortByTotal = <T extends Node>(obj: Record<string, T>): T[] =>
        Object.values(obj).sort((a, b) => b.total - a.total);
    return sortByTotal(root);
});

const grandTotal = computed(() => tree.value.reduce((sum, p) => sum + p.total, 0));
const columnTotals = computed(() =>
    dayColumns.value.map((col) => tree.value.reduce((sum, p) => sum + (p.perDay[col.key] ?? 0), 0))
);

// --- Expand / collapse ----------------------------------------------------
const expanded = ref<Set<string>>(new Set());
function toggle(key: string) {
    const next = new Set(expanded.value);
    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }
    expanded.value = next;
}
function expandAll() {
    const next = new Set<string>();
    for (const p of tree.value) {
        next.add(p.key);
        for (const t of Object.values(p.children)) {
            next.add(t.key);
        }
    }
    expanded.value = next;
}
function collapseAll() {
    expanded.value = new Set();
}

type Row = {
    level: 0 | 1 | 2;
    key: string;
    label: string;
    color: string | null;
    perDay: Record<string, number>;
    total: number;
    expandable: boolean;
    isOpen: boolean;
};

const visibleRows = computed<Row[]>(() => {
    const rows: Row[] = [];
    const sortByTotal = <T extends Node>(obj: Record<string, T>): T[] =>
        Object.values(obj).sort((a, b) => b.total - a.total);
    for (const project of tree.value) {
        const pOpen = expanded.value.has(project.key);
        rows.push({
            level: 0,
            key: project.key,
            label: project.label,
            color: project.color,
            perDay: project.perDay,
            total: project.total,
            expandable: Object.keys(project.children).length > 0,
            isOpen: pOpen,
        });
        if (!pOpen) {
            continue;
        }
        for (const task of sortByTotal(project.children)) {
            const tOpen = expanded.value.has(task.key);
            rows.push({
                level: 1,
                key: task.key,
                label: task.label,
                color: null,
                perDay: task.perDay,
                total: task.total,
                expandable: Object.keys(task.children).length > 0,
                isOpen: tOpen,
            });
            if (!tOpen) {
                continue;
            }
            for (const desc of sortByTotal(task.children)) {
                rows.push({
                    level: 2,
                    key: desc.key,
                    label: desc.label,
                    color: null,
                    perDay: desc.perDay,
                    total: desc.total,
                    expandable: false,
                    isOpen: false,
                });
            }
        }
    }
    return rows;
});

const gridTemplate = computed(
    () => `minmax(240px, 1.6fr) repeat(${dayColumns.value.length}, minmax(78px, 1fr)) 110px`
);

// --- Export (CSV / Excel / PDF), dependency-free --------------------------
function exportRows(): string[][] {
    const sortByTotal = <T extends Node>(obj: Record<string, T>): T[] =>
        Object.values(obj).sort((a, b) => b.total - a.total);
    const days = dayColumns.value.map((c) => c.key);
    const rows: string[][] = [];
    const line = (indent: string, node: Node) => [
        indent + node.label,
        ...days.map((k) => fmtDuration(node.perDay[k] ?? 0)),
        fmtDurationZero(node.total),
    ];
    for (const project of tree.value) {
        rows.push(line('', project));
        for (const task of sortByTotal(project.children)) {
            rows.push(line('    ', task));
            for (const desc of sortByTotal(task.children)) {
                rows.push(line('        ', desc));
            }
        }
    }
    rows.push([
        'Total',
        ...days.map((_, i) => fmtDurationZero(columnTotals.value[i] ?? 0)),
        fmtDurationZero(grandTotal.value),
    ]);
    return rows;
}

function exportTitle(): string {
    return `Weekly Detailed – ${weekRangeLabel.value}`;
}
function exportColumns(): string[] {
    return ['Project / Task / Description', ...dayColumns.value.map((c) => c.weekday + ' ' + c.date), 'Total'];
}
function fileBaseName(): string {
    return `weekly-detailed-${startDate.value.slice(0, 10)}_${endDate.value.slice(0, 10)}`.replace(
        /[^a-z0-9_\-]+/gi,
        '_'
    );
}
function triggerDownload(blob: Blob, filename: string) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
function csvCell(value: string): string {
    return `"${(value ?? '').replace(/"/g, '""')}"`;
}
function escapeHtml(value: string): string {
    return (value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function exportCsv() {
    const lines: string[] = [csvCell(exportTitle()), exportColumns().map(csvCell).join(',')];
    for (const row of exportRows()) {
        lines.push(row.map(csvCell).join(','));
    }
    triggerDownload(
        new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' }),
        `${fileBaseName()}.csv`
    );
}
function buildHtml(): string {
    const cols = exportColumns();
    const head = `<tr>${cols
        .map(
            (c) =>
                `<th style="background:#1f3a5f;color:#fff;padding:6px 8px;text-align:left;font-size:12px;">${escapeHtml(
                    c
                )}</th>`
        )
        .join('')}</tr>`;
    const body = exportRows()
        .map((row) => {
            const indentMatch = row[0]?.match(/^ */)?.[0].length ?? 0;
            const weight = indentMatch === 0 ? 'font-weight:700;' : indentMatch === 4 ? 'font-weight:600;' : '';
            return `<tr>${row
                .map(
                    (cell, i) =>
                        `<td style="padding:4px 8px;border-bottom:1px solid #e5e7eb;font-size:12px;white-space:pre;${
                            i === 0 ? weight : 'text-align:right;'
                        }">${escapeHtml(cell)}</td>`
                )
                .join('')}</tr>`;
        })
        .join('');
    return `<h2 style="font-family:Arial,sans-serif;">${escapeHtml(
        exportTitle()
    )}</h2><table style="border-collapse:collapse;width:100%;"><thead>${head}</thead><tbody>${body}</tbody></table>`;
}
function exportExcel() {
    const html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;">${buildHtml()}</body></html>`;
    triggerDownload(
        new Blob(['﻿' + html], { type: 'application/vnd.ms-excel;charset=utf-8;' }),
        `${fileBaseName()}.xls`
    );
}
function exportPdf() {
    const win = window.open('', '_blank');
    if (!win) {
        return;
    }
    win.document.write(
        `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(
            exportTitle()
        )}</title></head><body style="font-family:Arial,sans-serif;padding:24px;">${buildHtml()}<script>window.onload=function(){window.print();}<\/script></body></html>`
    );
    win.document.close();
}
async function downloadExport(format: ExportFormat) {
    if (format === 'pdf') {
        exportPdf();
    } else if (format === 'csv') {
        exportCsv();
    } else {
        exportExcel();
    }
}

// --- Save report ----------------------------------------------------------
const showCreateReportModal = ref(false);
const showPremiumModal = ref(false);

const savedProjectIds = computed<string[] | undefined>(() => {
    if (excludedProjects.value.length === 0) {
        return selectedProjects.value.length > 0 ? selectedProjects.value : undefined;
    }
    const base =
        selectedProjects.value.length > 0
            ? selectedProjects.value
            : projects.value.map((p) => p.id);
    const filtered = base.filter((id) => !excludedProjects.value.includes(id));
    return filtered.length > 0 ? filtered : ['00000000-0000-0000-0000-000000000000'];
});

const reportProperties = computed(
    () =>
        ({
            start: startUtc.value,
            end: endUtc.value,
            member_id: !canViewOthersTimeEntries()
                ? (getCurrentMembershipId() ?? undefined)
                : undefined,
            member_ids:
                showMemberFilter.value && selectedMembers.value.length > 0
                    ? selectedMembers.value
                    : undefined,
            project_ids: savedProjectIds.value,
            billable: null,
            group: 'project',
            sub_group: 'task',
            history_group: 'day',
            format: 'weekly-detailed',
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
            <PageTitle :icon="ChartBarIcon" title="Weekly Detailed"></PageTitle>
            <ReportingTabNavbar active="weekly-detailed" class="hidden sm:flex"></ReportingTabNavbar>
        </div>
        <div class="flex items-center gap-2">
            <SecondaryButton v-if="canCreateReports()" :icon="SaveIcon" @click="onSaveReportClick">
                Save Report
            </SecondaryButton>
            <ReportingExportButton :download="downloadExport"></ReportingExportButton>
        </div>
    </MainContainer>
    <MainContainer class="sm:hidden py-2 border-b border-default-background-separator">
        <ReportingTabNavbar active="weekly-detailed"></ReportingTabNavbar>
    </MainContainer>

    <ReportCreateModal
        v-model:show="showCreateReportModal"
        :properties="reportProperties"></ReportCreateModal>
    <UpgradeModal v-model:show="showPremiumModal">
        Saved &amp; shareable reports are only available in solidtime Professional.
    </UpgradeModal>

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
                    <span class="px-3 py-1.5 text-sm text-text-primary font-medium whitespace-nowrap">
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
                <MemberMultiselectDropdown v-if="showMemberFilter" v-model="selectedMembers">
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

    <MainContainer>
        <div class="pt-6 pb-10">
            <div class="flex items-center gap-2 pb-3">
                <SecondaryButton size="small" @click="expandAll">Expand all</SecondaryButton>
                <SecondaryButton size="small" @click="collapseAll">Collapse all</SecondaryButton>
                <span class="ml-auto text-sm text-text-tertiary">
                    Total: {{ fmtDurationZero(grandTotal) }}
                </span>
            </div>

            <div v-if="isLoading" class="py-16 text-center text-text-tertiary">Loading…</div>
            <div v-else-if="visibleRows.length === 0" class="py-16 text-center">
                <p class="text-lg text-text-primary font-medium">No time entries found</p>
                <p class="text-text-tertiary">Try a different week or filters</p>
            </div>

            <div v-else class="bg-secondary rounded-lg border border-card-border overflow-x-auto">
                <div class="min-w-max">
                    <!-- Header -->
                    <div
                        class="grid items-center border-b border-card-background-separator text-text-tertiary text-xs"
                        :style="`grid-template-columns: ${gridTemplate}`">
                        <div class="pl-4 py-2 font-semibold uppercase">
                            Project / Task / Description
                        </div>
                        <div
                            v-for="col in dayColumns"
                            :key="col.key"
                            class="py-2 px-2 text-right leading-tight">
                            <div class="font-medium text-text-secondary">{{ col.weekday }}</div>
                            <div>{{ col.date }}</div>
                        </div>
                        <div class="pr-6 py-2 text-right font-medium">Total</div>
                    </div>

                    <!-- Rows -->
                    <div
                        v-for="row in visibleRows"
                        :key="row.key"
                        class="grid items-center border-b border-card-background-separator text-sm hover:bg-tertiary transition"
                        :class="{
                            'bg-secondary': row.level === 0,
                            'text-text-secondary': row.level === 2,
                        }"
                        :style="`grid-template-columns: ${gridTemplate}`">
                        <div
                            class="py-2 flex items-center gap-1.5 min-w-0"
                            :style="`padding-left: ${16 + row.level * 22}px`">
                            <button
                                v-if="row.expandable"
                                type="button"
                                class="flex-shrink-0 text-text-tertiary hover:text-text-primary transition"
                                @click="toggle(row.key)">
                                <ChevronRightIcon
                                    class="w-4 h-4 transition-transform"
                                    :class="row.isOpen ? 'rotate-90' : ''" />
                            </button>
                            <span v-else class="w-4 flex-shrink-0"></span>
                            <span
                                v-if="row.level === 0"
                                class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                :style="`background-color: ${row.color ?? '#9ca3af'}`"></span>
                            <span
                                class="truncate"
                                :class="
                                    row.level === 0
                                        ? 'text-text-primary font-medium'
                                        : 'text-text-secondary'
                                ">
                                {{ row.label }}
                            </span>
                        </div>
                        <div
                            v-for="col in dayColumns"
                            :key="col.key"
                            class="py-2 px-2 text-right tabular-nums"
                            :class="row.perDay[col.key] ? 'text-text-primary' : 'text-text-quaternary'">
                            {{ row.perDay[col.key] ? fmtDuration(row.perDay[col.key] ?? 0) : '–' }}
                        </div>
                        <div
                            class="pr-6 py-2 text-right font-medium tabular-nums"
                            :class="row.level === 0 ? 'text-text-primary' : 'text-text-secondary'">
                            {{ fmtDurationZero(row.total) }}
                        </div>
                    </div>

                    <!-- Totals -->
                    <div
                        class="grid items-center text-sm font-medium text-text-secondary"
                        :style="`grid-template-columns: ${gridTemplate}`">
                        <div class="pl-4 py-2.5">Total</div>
                        <div
                            v-for="(total, index) in columnTotals"
                            :key="'total-' + index"
                            class="py-2.5 px-2 text-right tabular-nums"
                            :class="total ? '' : 'text-text-quaternary'">
                            {{ total ? fmtDuration(total) : '–' }}
                        </div>
                        <div class="pr-6 py-2.5 text-right tabular-nums text-text-primary">
                            {{ fmtDurationZero(grandTotal) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainContainer>
</template>

<style scoped></style>
