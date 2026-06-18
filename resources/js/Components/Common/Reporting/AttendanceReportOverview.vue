<script setup lang="ts">
import { ChartBarIcon, FolderIcon, UserGroupIcon } from '@heroicons/vue/20/solid';
import { computed, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import ReportingTabNavbar from '@/Components/Common/Reporting/ReportingTabNavbar.vue';
import ReportingExportButton from '@/Components/Common/Reporting/ReportingExportButton.vue';
import ReportCreateModal from '@/Components/Common/Report/ReportCreateModal.vue';
import UpgradeModal from '@/Components/Common/UpgradeModal.vue';
import { SaveIcon } from 'lucide-vue-next';
import ReportingFilterBadge from '@/Components/Common/Reporting/ReportingFilterBadge.vue';
import ReportingGroupBySelect from '@/Components/Common/Reporting/ReportingGroupBySelect.vue';
import MemberMultiselectDropdown from '@/Components/Common/Member/MemberMultiselectDropdown.vue';
import ProjectMultiselectDropdown from '@/Components/Common/Project/ProjectMultiselectDropdown.vue';
import DateRangePicker from '@/packages/ui/src/Input/DateRangePicker.vue';
import {
    SecondaryButton,
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/packages/ui/src';
import { api, type CreateReportBodyProperties } from '@/packages/api/src';
import type { ExportFormat } from '@/types/reporting';
import { getCurrentMembershipId, getCurrentOrganizationId, getCurrentRole } from '@/utils/useUser';
import { canCreateReports } from '@/utils/permissions';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { useMembersQuery } from '@/utils/useMembersQuery';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';

const { members } = useMembersQuery();
const { projects } = useProjectsQuery();
const { clients } = useClientsQuery();
const { tasks } = useTasksQuery();

const isEmployee = computed(() => getCurrentRole() === 'employee');

// Which dimension the lower matrix groups its rows by.
type RowGroup = 'project' | 'user' | 'task' | 'client';
const groupBy = ref<RowGroup>('project');
const groupByOptions: { value: RowGroup; label: string; icon: typeof FolderIcon }[] = [
    { value: 'project', label: 'Projects', icon: FolderIcon },
    { value: 'user', label: 'Members', icon: UserGroupIcon },
    { value: 'task', label: 'Tasks', icon: FolderIcon },
    { value: 'client', label: 'Clients', icon: UserGroupIcon },
];

// --- Controls -------------------------------------------------------------
function now() {
    return getLocalizedDayJs(getDayJsInstance()().format());
}
const startDate = ref<string>(now().startOf('month').format());
const endDate = ref<string>(now().endOf('day').format());

const selectedMembers = ref<string[]>([]);
const excludedProjects = ref<string[]>([]);
const view = ref<'daily' | 'project'>('daily');
const selectedProject = ref<string>('');

// Default the excluded set to a "Lunch / Break"-style project once projects load.
let lunchDefaulted = false;
watch(
    projects,
    (list) => {
        if (lunchDefaulted || excludedProjects.value.length > 0) {
            return;
        }
        const lunch = list.find((p) => /lunch|break/i.test(p.name));
        if (lunch) {
            excludedProjects.value = [lunch.id];
            lunchDefaulted = true;
        }
    },
    { immediate: true }
);

const memberName = computed(() => {
    if (selectedMembers.value.length === 0) {
        return 'All members';
    }
    if (selectedMembers.value.length === 1) {
        return members.value.find((m) => m.id === selectedMembers.value[0])?.name ?? 'Member';
    }
    return `${selectedMembers.value.length} members`;
});

const userNameByUserId = computed(() => {
    const map: Record<string, string> = {};
    for (const m of members.value) {
        map[m.user_id] = m.name;
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

const excludeLabel = computed(() => {
    if (excludedProjects.value.length === 0) {
        return 'Excluded';
    }
    return excludedProjects.value
        .map((id) => projectMap.value[id]?.name ?? 'Excluded')
        .join(', ');
});

// --- Data fetch -----------------------------------------------------------
type Entry = {
    start: string;
    end: string | null;
    duration: number | null;
    description: string | null;
    project_id: string | null;
    task_id: string | null;
    user_id: string | null;
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
    // Guard against runaway loops.
    for (let i = 0; i < 200; i++) {
        const res = await api.getTimeEntries({
            params: { organization: orgId },
            queries: {
                start: startUtc.value,
                end: endUtc.value,
                member_id: isEmployee.value ? (getCurrentMembershipId() ?? undefined) : undefined,
                member_ids:
                    !isEmployee.value && selectedMembers.value.length > 0
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
        'attendanceEntries',
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

function fmtClock(iso: string | null): string {
    return iso ? getLocalizedDayJs(iso).format('HH:mm:ss') : '';
}

// --- Daily attendance computation ----------------------------------------
const excludeSet = computed(() => new Set(excludedProjects.value));

const dayKeys = computed(() => {
    const set = new Set<string>();
    for (const e of entries.value ?? []) {
        set.add(dayKeyOf(e.start));
    }
    return Array.from(set).sort();
});

type DayMetric = {
    startIso: string | null;
    endIso: string | null;
    total: number;
    excluded: number;
};

const dailyMetrics = computed<Record<string, DayMetric>>(() => {
    const map: Record<string, DayMetric> = {};
    for (const e of entries.value ?? []) {
        const key = dayKeyOf(e.start);
        if (!map[key]) {
            map[key] = { startIso: null, endIso: null, total: 0, excluded: 0 };
        }
        const m = map[key];
        const dur = durationSeconds(e);
        m.total += dur;
        if (e.project_id && excludeSet.value.has(e.project_id)) {
            m.excluded += dur;
        }
        if (m.startIso === null || getLocalizedDayJs(e.start).isBefore(getLocalizedDayJs(m.startIso))) {
            m.startIso = e.start;
        }
        const endIso = e.end ?? now().format();
        if (m.endIso === null || getLocalizedDayJs(endIso).isAfter(getLocalizedDayJs(m.endIso))) {
            m.endIso = endIso;
        }
    }
    return map;
});

// <group> x day matrix
type MatrixRow = {
    key: string;
    label: string;
    color: string | null;
    perDay: Record<string, number>;
    total: number;
};

// Resolve the grouping key + display for one entry, based on the selected dimension.
function rowDescriptor(e: Entry): { key: string; label: string; color: string | null } {
    if (groupBy.value === 'user') {
        const id = e.user_id;
        return {
            key: id ?? '__none__',
            label: id ? (userNameByUserId.value[id] ?? 'Unknown member') : 'No member',
            color: null,
        };
    }
    if (groupBy.value === 'task') {
        const id = e.task_id;
        return {
            key: id ?? '__none__',
            label: id ? (taskMap.value[id] ?? 'Unknown task') : 'No task',
            color: null,
        };
    }
    if (groupBy.value === 'client') {
        const client = e.project_id ? (projectMap.value[e.project_id]?.client ?? null) : null;
        return { key: client ?? '__none__', label: client ?? 'No Client', color: null };
    }
    // project (default)
    const id = e.project_id;
    return {
        key: id ?? '__none__',
        label: projectLabel(id),
        color: id ? (projectMap.value[id]?.color ?? null) : null,
    };
}

const matrixRows = computed<MatrixRow[]>(() => {
    const map: Record<string, MatrixRow> = {};
    for (const e of entries.value ?? []) {
        const d = rowDescriptor(e);
        if (!map[d.key]) {
            map[d.key] = { key: d.key, label: d.label, color: d.color, perDay: {}, total: 0 };
        }
        const row = map[d.key]!;
        const dayKey = dayKeyOf(e.start);
        const dur = durationSeconds(e);
        row.perDay[dayKey] = (row.perDay[dayKey] ?? 0) + dur;
        row.total += dur;
    }
    return Object.values(map).sort((a, b) => b.total - a.total);
});

const groupByLabel = computed(
    () => groupByOptions.find((o) => o.value === groupBy.value)?.label ?? 'Projects'
);

const metricRows = computed(() => {
    const day = (k: string) => dailyMetrics.value[k];
    return [
        {
            label: 'Start Time',
            values: dayKeys.value.map((k) => fmtClock(day(k)?.startIso ?? null)),
        },
        {
            label: 'End Time',
            values: dayKeys.value.map((k) => fmtClock(day(k)?.endIso ?? null)),
        },
        {
            label: `${excludeLabel.value} Time`,
            values: dayKeys.value.map((k) => fmtDurationZero(day(k)?.excluded ?? 0)),
        },
        {
            label: 'Total Time',
            values: dayKeys.value.map((k) => fmtDurationZero(day(k)?.total ?? 0)),
        },
        {
            label: `Excluding ${excludeLabel.value}`,
            values: dayKeys.value.map((k) =>
                fmtDurationZero((day(k)?.total ?? 0) - (day(k)?.excluded ?? 0))
            ),
        },
    ];
});

const grandTotal = computed(() =>
    (entries.value ?? []).reduce((sum, e) => sum + durationSeconds(e), 0)
);

// --- Project-wise list ----------------------------------------------------
const projectWiseRows = computed(() => {
    if (!selectedProject.value) {
        return [];
    }
    const byDay: Record<string, { descriptions: Set<string>; total: number }> = {};
    for (const e of entries.value ?? []) {
        if (e.project_id !== selectedProject.value) {
            continue;
        }
        const key = dayKeyOf(e.start);
        if (!byDay[key]) {
            byDay[key] = { descriptions: new Set(), total: 0 };
        }
        if (e.description && e.description.trim() !== '') {
            byDay[key].descriptions.add(e.description.trim());
        }
        byDay[key].total += durationSeconds(e);
    }
    return Object.keys(byDay)
        .sort()
        .map((key) => {
            const day = byDay[key]!;
            return {
                date: getLocalizedDayJs(key).format('DD/MM/YYYY'),
                description: Array.from(day.descriptions).join(' | '),
                total: day.total,
            };
        });
});

const projectWiseGrandTotal = computed(() =>
    projectWiseRows.value.reduce((sum, r) => sum + r.total, 0)
);

const rangeLabel = computed(
    () =>
        `${getLocalizedDayJs(startDate.value).format('DD MMM YYYY')} – ${getLocalizedDayJs(
            endDate.value
        ).format('DD MMM YYYY')}`
);

const gridTemplate = computed(
    () => `minmax(160px, 1.4fr) repeat(${dayKeys.value.length}, minmax(80px, 1fr)) 110px`
);

// --- Export (CSV / Excel / PDF), dependency-free --------------------------
type Block = { title: string; columns: string[]; rows: string[][] };

function currentBlocks(): { title: string; blocks: Block[] } {
    if (view.value === 'project') {
        return {
            title: `Project-Wise Hours – ${projectLabel(selectedProject.value || null)}`,
            blocks: [
                {
                    title: '',
                    columns: ['Date', 'Combined Description', 'Total Hours Worked'],
                    rows: [
                        ...projectWiseRows.value.map((r) => [
                            r.date,
                            r.description,
                            fmtDurationZero(r.total),
                        ]),
                        ['Grand Total', '', fmtDurationZero(projectWiseGrandTotal.value)],
                    ],
                },
            ],
        };
    }
    const dayHeaders = dayKeys.value;
    return {
        title: `Attendance – ${memberName.value} (${rangeLabel.value})`,
        blocks: [
            {
                title: 'Metrics',
                columns: ['Metric', ...dayHeaders],
                rows: metricRows.value.map((r) => [r.label, ...r.values]),
            },
            {
                title: groupByLabel.value,
                columns: [groupByLabel.value, ...dayHeaders, 'Total'],
                rows: [
                    ...matrixRows.value.map((r) => [
                        r.label,
                        ...dayHeaders.map((k) => fmtDuration(r.perDay[k] ?? 0)),
                        fmtDurationZero(r.total),
                    ]),
                    [
                        'Total',
                        ...dayHeaders.map((k) =>
                            fmtDurationZero(dailyMetrics.value[k]?.total ?? 0)
                        ),
                        fmtDurationZero(grandTotal.value),
                    ],
                ],
            },
        ],
    };
}

function fileBaseName(): string {
    const base =
        view.value === 'project'
            ? `project-wise-${projectLabel(selectedProject.value || null)}`
            : `attendance-${memberName.value}`;
    return `${base}-${startDate.value.slice(0, 10)}_${endDate.value.slice(0, 10)}`.replace(
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

function exportCsv() {
    const { blocks } = currentBlocks();
    const lines: string[] = [];
    for (const block of blocks) {
        if (block.title) {
            lines.push(csvCell(block.title));
        }
        lines.push(block.columns.map(csvCell).join(','));
        for (const row of block.rows) {
            lines.push(row.map(csvCell).join(','));
        }
        lines.push('');
    }
    triggerDownload(
        new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' }),
        `${fileBaseName()}.csv`
    );
}

function escapeHtml(value: string): string {
    return (value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function blocksToHtml(title: string, blocks: Block[]): string {
    const tables = blocks
        .map((block) => {
            const head = `<tr>${block.columns
                .map(
                    (c) =>
                        `<th style="background:#1f3a5f;color:#fff;padding:6px 10px;text-align:left;font-size:12px;">${escapeHtml(
                            c
                        )}</th>`
                )
                .join('')}</tr>`;
            const body = block.rows
                .map(
                    (row) =>
                        `<tr>${row
                            .map(
                                (cell, i) =>
                                    `<td style="padding:5px 10px;border-bottom:1px solid #e5e7eb;font-size:12px;${
                                        i === 0 ? 'font-weight:600;' : 'text-align:right;'
                                    }">${escapeHtml(cell)}</td>`
                            )
                            .join('')}</tr>`
                )
                .join('');
            const caption = block.title
                ? `<caption style="text-align:left;font-weight:700;padding:8px 0;">${escapeHtml(
                      block.title
                  )}</caption>`
                : '';
            return `<table style="border-collapse:collapse;margin-bottom:24px;width:100%;">${caption}<thead>${head}</thead><tbody>${body}</tbody></table>`;
        })
        .join('');
    return `<h2 style="font-family:Arial,sans-serif;">${escapeHtml(title)}</h2>${tables}`;
}

function exportExcel() {
    const { title, blocks } = currentBlocks();
    const html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;">${blocksToHtml(
        title,
        blocks
    )}</body></html>`;
    triggerDownload(
        new Blob(['﻿' + html], { type: 'application/vnd.ms-excel;charset=utf-8;' }),
        `${fileBaseName()}.xls`
    );
}

function exportPdf() {
    const { title, blocks } = currentBlocks();
    const win = window.open('', '_blank');
    if (!win) {
        return;
    }
    win.document.write(
        `<!doctype html><html><head><meta charset="utf-8"><title>${escapeHtml(
            title
        )}</title></head><body style="font-family:Arial,sans-serif;padding:24px;">${blocksToHtml(
            title,
            blocks
        )}<script>window.onload=function(){window.print();}<\/script></body></html>`
    );
    win.document.close();
}

async function downloadExport(format: ExportFormat) {
    if (format === 'pdf') {
        exportPdf();
    } else if (format === 'csv') {
        exportCsv();
    } else {
        // xlsx and ods both open from the HTML-table workbook in Excel/LibreOffice.
        exportExcel();
    }
}

// --- Save report ----------------------------------------------------------
const showCreateReportModal = ref(false);
const showPremiumModal = ref(false);

// Express the "exclude" filter as the equivalent include-list for the saved report.
const savedProjectIds = computed<string[] | undefined>(() => {
    if (excludedProjects.value.length === 0) {
        return undefined;
    }
    const filtered = projects.value
        .map((p) => p.id)
        .filter((id) => !excludedProjects.value.includes(id));
    return filtered.length > 0 ? filtered : ['00000000-0000-0000-0000-000000000000'];
});

const reportProperties = computed(
    () =>
        ({
            start: startUtc.value,
            end: endUtc.value,
            member_id: isEmployee.value ? (getCurrentMembershipId() ?? undefined) : undefined,
            member_ids:
                !isEmployee.value && selectedMembers.value.length > 0
                    ? selectedMembers.value
                    : undefined,
            project_ids: savedProjectIds.value,
            billable: null,
            group: groupBy.value,
            sub_group: 'day',
            history_group: 'day',
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
            <PageTitle :icon="ChartBarIcon" title="Attendance Report"></PageTitle>
            <ReportingTabNavbar active="attendance" class="hidden sm:flex"></ReportingTabNavbar>
        </div>
        <div class="flex items-center gap-2">
            <SecondaryButton v-if="canCreateReports()" :icon="SaveIcon" @click="onSaveReportClick">
                Save Report
            </SecondaryButton>
            <ReportingExportButton :download="downloadExport"></ReportingExportButton>
        </div>
    </MainContainer>
    <MainContainer class="sm:hidden py-2 border-b border-default-background-separator">
        <ReportingTabNavbar active="attendance"></ReportingTabNavbar>
    </MainContainer>

    <ReportCreateModal
        v-model:show="showCreateReportModal"
        :properties="reportProperties"></ReportCreateModal>
    <UpgradeModal v-model:show="showPremiumModal">
        Saved &amp; shareable reports are only available in solidtime Professional.
    </UpgradeModal>

    <!-- Controls -->
    <div class="py-2.5 w-full border-b border-default-background-separator">
        <MainContainer class="flex flex-wrap items-center gap-3">
            <div class="flex items-center rounded-lg border border-input-border overflow-hidden">
                <button
                    type="button"
                    class="px-3 py-1.5 text-sm transition"
                    :class="
                        view === 'daily'
                            ? 'bg-secondary text-text-primary font-medium'
                            : 'text-text-secondary hover:text-text-primary'
                    "
                    @click="view = 'daily'">
                    Daily Attendance
                </button>
                <button
                    type="button"
                    class="px-3 py-1.5 text-sm transition border-l border-input-border"
                    :class="
                        view === 'project'
                            ? 'bg-secondary text-text-primary font-medium'
                            : 'text-text-secondary hover:text-text-primary'
                    "
                    @click="view = 'project'">
                    Project-Wise
                </button>
            </div>

            <div class="w-[260px] max-w-full">
                <DateRangePicker v-model:start="startDate" v-model:end="endDate" />
            </div>

            <MemberMultiselectDropdown v-if="!isEmployee" v-model="selectedMembers">
                <template #trigger>
                    <ReportingFilterBadge
                        :count="selectedMembers.length"
                        :active="selectedMembers.length > 0"
                        title="Users"
                        :icon="UserGroupIcon" />
                </template>
            </MemberMultiselectDropdown>

            <div v-if="view === 'daily'" class="flex items-center gap-2 text-sm">
                <span class="text-text-secondary">Group by</span>
                <ReportingGroupBySelect v-model="groupBy" :group-by-options="groupByOptions" />
            </div>

            <ProjectMultiselectDropdown v-if="view === 'daily'" v-model="excludedProjects">
                <template #trigger>
                    <ReportingFilterBadge
                        :count="excludedProjects.length"
                        :active="excludedProjects.length > 0"
                        title="Exclude projects"
                        :icon="FolderIcon" />
                </template>
            </ProjectMultiselectDropdown>

            <Select v-if="view === 'project'" v-model="selectedProject">
                <SelectTrigger size="sm" class="min-w-[200px]">
                    <SelectValue placeholder="Select a project">
                        {{ selectedProject ? projectLabel(selectedProject) : 'Select a project' }}
                    </SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="p in projects" :key="p.id" :value="p.id">
                        {{ p.client_id ? (clientMap[p.client_id] ?? '') + ' - ' : '' }}{{ p.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </MainContainer>
    </div>

    <!-- Daily attendance -->
    <MainContainer v-if="view === 'daily'">
        <div v-if="isLoading" class="py-16 text-center text-text-tertiary">Loading…</div>
        <div v-else-if="dayKeys.length === 0" class="py-16 text-center">
            <p class="text-lg text-text-primary font-medium">No time entries found</p>
            <p class="text-text-tertiary">Try a different date range or member</p>
        </div>
        <div v-else class="pt-6 pb-10 space-y-8">
            <!-- Metrics -->
            <div class="bg-secondary rounded-lg border border-card-border overflow-x-auto">
                <div class="min-w-max">
                    <div
                        class="grid border-b border-card-background-separator text-text-tertiary text-xs"
                        :style="`grid-template-columns: ${gridTemplate}`">
                        <div class="pl-6 py-2 font-semibold uppercase">Metric</div>
                        <div
                            v-for="k in dayKeys"
                            :key="k"
                            class="py-2 px-2 text-right font-medium">
                            {{ k }}
                        </div>
                        <div class="pr-6 py-2"></div>
                    </div>
                    <div
                        v-for="row in metricRows"
                        :key="row.label"
                        class="grid border-b border-card-background-separator text-sm"
                        :style="`grid-template-columns: ${gridTemplate}`">
                        <div class="pl-6 py-2 font-medium text-text-secondary">{{ row.label }}</div>
                        <div
                            v-for="(val, i) in row.values"
                            :key="i"
                            class="py-2 px-2 text-right tabular-nums text-text-primary">
                            {{ val }}
                        </div>
                        <div class="pr-6 py-2"></div>
                    </div>
                </div>
            </div>

            <!-- Projects x day -->
            <div class="bg-secondary rounded-lg border border-card-border overflow-x-auto">
                <div class="min-w-max">
                    <div
                        class="grid border-b border-card-background-separator text-text-tertiary text-xs"
                        :style="`grid-template-columns: ${gridTemplate}`">
                        <div class="pl-6 py-2 font-semibold uppercase">{{ groupByLabel }}</div>
                        <div
                            v-for="k in dayKeys"
                            :key="k"
                            class="py-2 px-2 text-right font-medium">
                            {{ k }}
                        </div>
                        <div class="pr-6 py-2 text-right font-medium">Total</div>
                    </div>
                    <div
                        v-for="row in matrixRows"
                        :key="row.key"
                        class="grid border-b border-card-background-separator text-sm hover:bg-tertiary transition"
                        :style="`grid-template-columns: ${gridTemplate}`">
                        <div class="pl-6 py-2 flex items-center gap-2 min-w-0">
                            <span
                                class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                :style="`background-color: ${row.color ?? '#9ca3af'}`"></span>
                            <span class="truncate text-text-primary">{{ row.label }}</span>
                        </div>
                        <div
                            v-for="k in dayKeys"
                            :key="k"
                            class="py-2 px-2 text-right tabular-nums"
                            :class="row.perDay[k] ? 'text-text-primary' : 'text-text-quaternary'">
                            {{ row.perDay[k] ? fmtDuration(row.perDay[k]) : '–' }}
                        </div>
                        <div class="pr-6 py-2 text-right font-medium tabular-nums text-text-primary">
                            {{ fmtDurationZero(row.total) }}
                        </div>
                    </div>
                    <div
                        class="grid text-sm font-medium text-text-secondary"
                        :style="`grid-template-columns: ${gridTemplate}`">
                        <div class="pl-6 py-2.5">Total</div>
                        <div
                            v-for="k in dayKeys"
                            :key="k"
                            class="py-2.5 px-2 text-right tabular-nums">
                            {{ fmtDurationZero(dailyMetrics[k]?.total ?? 0) }}
                        </div>
                        <div class="pr-6 py-2.5 text-right tabular-nums text-text-primary">
                            {{ fmtDurationZero(grandTotal) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainContainer>

    <!-- Project-wise list -->
    <MainContainer v-else>
        <div class="pt-6 pb-10">
            <div v-if="!selectedProject" class="py-16 text-center text-text-tertiary">
                Select a project to see the day-by-day breakdown.
            </div>
            <div
                v-else-if="projectWiseRows.length === 0 && !isLoading"
                class="py-16 text-center">
                <p class="text-lg text-text-primary font-medium">No time entries found</p>
                <p class="text-text-tertiary">Try a different date range or project</p>
            </div>
            <div v-else class="bg-secondary rounded-lg border border-card-border overflow-hidden">
                <div
                    class="grid text-text-tertiary text-xs border-b border-card-background-separator"
                    style="grid-template-columns: 130px 1fr 140px">
                    <div class="pl-6 py-2.5 font-semibold uppercase">Date</div>
                    <div class="py-2.5 font-semibold uppercase">Combined Description</div>
                    <div class="pr-6 py-2.5 text-right font-semibold uppercase">
                        Total Hours Worked
                    </div>
                </div>
                <div
                    v-for="row in projectWiseRows"
                    :key="row.date"
                    class="grid text-sm border-b border-card-background-separator"
                    style="grid-template-columns: 130px 1fr 140px">
                    <div class="pl-6 py-2.5 text-text-secondary whitespace-nowrap">{{ row.date }}</div>
                    <div class="py-2.5 text-text-primary pr-4">{{ row.description }}</div>
                    <div class="pr-6 py-2.5 text-right tabular-nums text-text-primary">
                        {{ fmtDurationZero(row.total) }}
                    </div>
                </div>
                <div
                    class="grid text-sm font-semibold text-text-primary"
                    style="grid-template-columns: 130px 1fr 140px">
                    <div class="pl-6 py-3">Grand Total</div>
                    <div class="py-3"></div>
                    <div class="pr-6 py-3 text-right tabular-nums">
                        {{ fmtDurationZero(projectWiseGrandTotal) }}
                    </div>
                </div>
            </div>
        </div>
    </MainContainer>
</template>

<style scoped></style>
