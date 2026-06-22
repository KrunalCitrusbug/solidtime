// Pure helpers to reproduce the custom report layouts (weekly matrix,
// weekly-detailed tree, attendance metrics) in the read-only shared report
// view, from the raw entries + reference data returned by the public endpoint.
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';

export type RawEntry = {
    start: string;
    end: string | null;
    description: string | null;
    project_id: string | null;
    task_id: string | null;
    user_id: string | null;
};

export type RawReferences = {
    projects: { id: string; name: string; color: string | null; client_id: string | null }[];
    clients: { id: string; name: string }[];
    tasks: { id: string; name: string }[];
    users: { id: string; name: string }[];
} | null;

export function buildRefMaps(refs: RawReferences) {
    const clientMap: Record<string, string> = {};
    for (const c of refs?.clients ?? []) {
        clientMap[c.id] = c.name;
    }
    const projectMap: Record<string, { name: string; color: string | null; client: string | null }> =
        {};
    for (const p of refs?.projects ?? []) {
        projectMap[p.id] = {
            name: p.name,
            color: p.color,
            client: p.client_id ? (clientMap[p.client_id] ?? null) : null,
        };
    }
    const taskMap: Record<string, string> = {};
    for (const t of refs?.tasks ?? []) {
        taskMap[t.id] = t.name;
    }
    const userMap: Record<string, string> = {};
    for (const u of refs?.users ?? []) {
        userMap[u.id] = u.name;
    }
    const projectLabel = (id: string | null): string => {
        if (id === null) {
            return 'No Project';
        }
        const p = projectMap[id];
        return p ? (p.client ? `${p.client} - ${p.name}` : p.name) : 'No Project';
    };
    return { clientMap, projectMap, taskMap, userMap, projectLabel };
}

function now() {
    return getLocalizedDayJs(getDayJsInstance()().format());
}

export function durationSeconds(e: RawEntry): number {
    const end = e.end ? getLocalizedDayJs(e.end) : now();
    return Math.max(0, end.diff(getLocalizedDayJs(e.start), 'second'));
}

export function dayKeyOf(iso: string): string {
    return getLocalizedDayJs(iso).format('YYYY-MM-DD');
}

export function fmtDuration(seconds: number): string {
    if (!seconds) {
        return '';
    }
    const s = Math.round(seconds);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
}

export function fmtDurationZero(seconds: number): string {
    return fmtDuration(seconds) || '0:00:00';
}

function fmtClock(iso: string | null): string {
    return iso ? getLocalizedDayJs(iso).format('HH:mm:ss') : '';
}

// --- Day columns from a start/end range ----------------------------------
export function dayColumnsBetween(start: string, end: string) {
    const cols: { key: string; weekday: string; date: string }[] = [];
    let day = getLocalizedDayJs(start).startOf('day');
    const last = getLocalizedDayJs(end).startOf('day');
    let guard = 0;
    while (!day.isAfter(last) && guard < 366) {
        cols.push({
            key: day.format('YYYY-MM-DD'),
            weekday: day.format('ddd'),
            date: day.format('MMM D'),
        });
        day = day.add(1, 'day');
        guard++;
    }
    return cols;
}

// --- Weekly-detailed: Project -> Task -> Description ----------------------
export type DetailedNode = {
    key: string;
    label: string;
    color: string | null;
    perDay: Record<string, number>;
    total: number;
    children: DetailedNode[];
    level: 0 | 1 | 2;
};

export function computeDetailedTree(
    entries: RawEntry[],
    maps: ReturnType<typeof buildRefMaps>
): DetailedNode[] {
    type Acc = {
        key: string;
        label: string;
        color: string | null;
        perDay: Record<string, number>;
        total: number;
        children: Record<string, Acc>;
        level: 0 | 1 | 2;
    };
    const root: Record<string, Acc> = {};
    const add = (node: Acc, day: string, dur: number) => {
        node.perDay[day] = (node.perDay[day] ?? 0) + dur;
        node.total += dur;
    };
    for (const e of entries) {
        const dur = durationSeconds(e);
        const day = dayKeyOf(e.start);
        const pKey = e.project_id ?? '__none__';
        if (!root[pKey]) {
            root[pKey] = {
                key: pKey,
                label: maps.projectLabel(e.project_id),
                color: e.project_id ? (maps.projectMap[e.project_id]?.color ?? null) : null,
                perDay: {},
                total: 0,
                children: {},
                level: 0,
            };
        }
        const project = root[pKey];
        add(project, day, dur);
        const tKey = e.task_id ?? '__none__';
        if (!project.children[tKey]) {
            project.children[tKey] = {
                key: `${pKey}|${tKey}`,
                label: e.task_id ? (maps.taskMap[e.task_id] ?? 'Unknown task') : 'No task',
                color: null,
                perDay: {},
                total: 0,
                children: {},
                level: 1,
            };
        }
        const task = project.children[tKey];
        add(task, day, dur);
        const dRaw = e.description && e.description.trim() !== '' ? e.description.trim() : null;
        const dKey = dRaw ?? '__none__';
        if (!task.children[dKey]) {
            task.children[dKey] = {
                key: `${pKey}|${tKey}|${dKey}`,
                label: dRaw ?? 'No description',
                color: null,
                perDay: {},
                total: 0,
                children: {},
                level: 2,
            };
        }
        add(task.children[dKey], day, dur);
    }
    const toSorted = (obj: Record<string, Acc>): DetailedNode[] =>
        Object.values(obj)
            .sort((a, b) => b.total - a.total)
            .map((n) => ({
                key: n.key,
                label: n.label,
                color: n.color,
                perDay: n.perDay,
                total: n.total,
                level: n.level,
                children: toSorted(n.children),
            }));
    return toSorted(root);
}

// --- Attendance ----------------------------------------------------------
export type AttendanceResult = {
    dayKeys: string[];
    metricRows: { label: string; values: string[] }[];
    matrixRows: { key: string; label: string; color: string | null; perDay: Record<string, number>; total: number }[];
    columnTotals: number[];
    grandTotal: number;
};

export function computeAttendance(
    entries: RawEntry[],
    maps: ReturnType<typeof buildRefMaps>,
    excludeProjectIds: string[],
    groupBy: 'project' | 'user' | 'task' | 'client'
): AttendanceResult {
    const excludeSet = new Set(excludeProjectIds);
    const dayKeysSet = new Set<string>();
    for (const e of entries) {
        dayKeysSet.add(dayKeyOf(e.start));
    }
    const dayKeys = Array.from(dayKeysSet).sort();

    type DayMetric = { startIso: string | null; endIso: string | null; total: number; excluded: number };
    const daily: Record<string, DayMetric> = {};
    for (const e of entries) {
        const k = dayKeyOf(e.start);
        if (!daily[k]) {
            daily[k] = { startIso: null, endIso: null, total: 0, excluded: 0 };
        }
        const m = daily[k];
        const dur = durationSeconds(e);
        m.total += dur;
        if (e.project_id && excludeSet.has(e.project_id)) {
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

    const excludeLabel =
        excludeProjectIds.length === 0
            ? 'Excluded'
            : excludeProjectIds.map((id) => maps.projectMap[id]?.name ?? 'Excluded').join(', ');

    const metricRows = [
        { label: 'Start Time', values: dayKeys.map((k) => fmtClock(daily[k]?.startIso ?? null)) },
        { label: 'End Time', values: dayKeys.map((k) => fmtClock(daily[k]?.endIso ?? null)) },
        { label: `${excludeLabel} Time`, values: dayKeys.map((k) => fmtDurationZero(daily[k]?.excluded ?? 0)) },
        { label: 'Total Time', values: dayKeys.map((k) => fmtDurationZero(daily[k]?.total ?? 0)) },
        {
            label: `Excluding ${excludeLabel}`,
            values: dayKeys.map((k) => fmtDurationZero((daily[k]?.total ?? 0) - (daily[k]?.excluded ?? 0))),
        },
    ];

    const rowDescriptor = (e: RawEntry): { key: string; label: string; color: string | null } => {
        if (groupBy === 'user') {
            return { key: e.user_id ?? '__none__', label: e.user_id ? (maps.userMap[e.user_id] ?? 'Unknown member') : 'No member', color: null };
        }
        if (groupBy === 'task') {
            return { key: e.task_id ?? '__none__', label: e.task_id ? (maps.taskMap[e.task_id] ?? 'Unknown task') : 'No task', color: null };
        }
        if (groupBy === 'client') {
            const client = e.project_id ? (maps.projectMap[e.project_id]?.client ?? null) : null;
            return { key: client ?? '__none__', label: client ?? 'No Client', color: null };
        }
        return {
            key: e.project_id ?? '__none__',
            label: maps.projectLabel(e.project_id),
            color: e.project_id ? (maps.projectMap[e.project_id]?.color ?? null) : null,
        };
    };

    const rowsMap: Record<string, { key: string; label: string; color: string | null; perDay: Record<string, number>; total: number }> = {};
    for (const e of entries) {
        const d = rowDescriptor(e);
        if (!rowsMap[d.key]) {
            rowsMap[d.key] = { key: d.key, label: d.label, color: d.color, perDay: {}, total: 0 };
        }
        const row = rowsMap[d.key]!;
        const day = dayKeyOf(e.start);
        const dur = durationSeconds(e);
        row.perDay[day] = (row.perDay[day] ?? 0) + dur;
        row.total += dur;
    }
    const matrixRows = Object.values(rowsMap).sort((a, b) => b.total - a.total);
    const columnTotals = dayKeys.map((k) => matrixRows.reduce((s, r) => s + (r.perDay[k] ?? 0), 0));
    const grandTotal = entries.reduce((s, e) => s + durationSeconds(e), 0);

    return { dayKeys, metricRows, matrixRows, columnTotals, grandTotal };
}
