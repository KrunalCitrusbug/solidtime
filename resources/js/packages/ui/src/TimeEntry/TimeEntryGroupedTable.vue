<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type {
    CreateClientBody,
    CreateProjectBody,
    CreateTimeEntryBody,
    Project,
    Tag,
    Task,
    TimeEntry,
    Client,
    Member,
} from '@/packages/api/src';
import { getDayJsInstance, getLocalizedDateFromTimestamp } from '@/packages/ui/src/utils/time';
import TimeEntryAggregateRow from '@/packages/ui/src/TimeEntry/TimeEntryAggregateRow.vue';
import TimeEntryRowHeading from '@/packages/ui/src/TimeEntry/TimeEntryRowHeading.vue';
import TimeEntryWeekHeading from '@/packages/ui/src/TimeEntry/TimeEntryWeekHeading.vue';
import TimeEntryRow from '@/packages/ui/src/TimeEntry/TimeEntryRow.vue';
import type { TimeEntriesGroupedByType } from '@/types/time-entries';
import { getInitialWeekRange } from '@/utils/useTimeEntriesCalendarQuery';

const selectedTimeEntries = defineModel<TimeEntry[]>('selected', {
    default: [],
});

const props = withDefaults(
    defineProps<{
        timeEntries: TimeEntry[];
        projects: Project[];
        tasks: Task[];
        tags: Tag[];
        clients: Client[];
        createTag: (name: string) => Promise<Tag | undefined>;
        updateTimeEntry: (entry: TimeEntry) => void;
        updateTimeEntries: (ids: string[], changes: Partial<TimeEntry>) => void;
        deleteTimeEntries: (entries: TimeEntry[]) => void;
        createTimeEntry: (entry: Omit<CreateTimeEntryBody, 'member_id'>) => void;
        createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
        createClient: (client: CreateClientBody) => Promise<Client | undefined>;
        currency: string;
        organizationBillableRate: number | null;
        enableEstimatedTime: boolean;
        canCreateProject: boolean;
        groupSimilarTimeEntries?: boolean;
        readOnly?: boolean;
        members?: Member[];
        showMember?: boolean;
        allowMemberAssignment?: boolean;
    }>(),
    {
        groupSimilarTimeEntries: true,
    }
);

type WeekGroup = {
    weekStart: string;
    weekRangeDisplay: string;
    isCurrentWeek: boolean;
    weekTotal: number;
    days: { date: string; entries: TimeEntriesGroupedByType[] }[];
};

function groupEntriesByDay(timeEntries: TimeEntry[]): Record<string, TimeEntriesGroupedByType[]> {
    const groupedEntriesByDay: Record<string, TimeEntry[]> = {};
    for (const entry of timeEntries) {
        if (entry.end === null) {
            continue;
        }
        const dayKey = getLocalizedDateFromTimestamp(entry.start);
        groupedEntriesByDay[dayKey] = [...(groupedEntriesByDay[dayKey] ?? []), entry];
    }

    const groupedEntriesByDayAndType: Record<string, TimeEntriesGroupedByType[]> = {};
    for (const dailyEntriesKey in groupedEntriesByDay) {
        const dailyEntries = groupedEntriesByDay[dailyEntriesKey]!;
        const newDailyEntries: TimeEntriesGroupedByType[] = [];

        for (const entry of dailyEntries) {
            if (!props.groupSimilarTimeEntries) {
                newDailyEntries.push({ ...entry, timeEntries: [entry] });
                continue;
            }

            const oldEntriesIndex = newDailyEntries.findIndex(
                (e) =>
                    e.project_id === entry.project_id &&
                    e.task_id === entry.task_id &&
                    e.billable === entry.billable &&
                    e.description === entry.description
            );
            if (oldEntriesIndex !== -1 && newDailyEntries[oldEntriesIndex]) {
                const existingEntry = newDailyEntries[oldEntriesIndex]!;
                existingEntry.timeEntries.push(entry);
                existingEntry.duration = (existingEntry.duration ?? 0) + (entry?.duration ?? 0);

                if (
                    getDayJsInstance()(entry.start).isBefore(
                        getDayJsInstance()(existingEntry.start)
                    )
                ) {
                    existingEntry.start = entry.start;
                }
                if (getDayJsInstance()(entry.end).isAfter(getDayJsInstance()(existingEntry.end))) {
                    existingEntry.end = entry.end;
                }
            } else {
                newDailyEntries.push({ ...entry, timeEntries: [entry] });
            }
        }

        groupedEntriesByDayAndType[dailyEntriesKey] = newDailyEntries;
    }

    return groupedEntriesByDayAndType;
}

function getWeekStartKey(dateStr: string): string {
    return getDayJsInstance()(dateStr).startOf('week').format('YYYY-MM-DD');
}

function formatWeekRange(weekStart: string): string {
    const start = getDayJsInstance()(weekStart);
    const end = start.add(6, 'day');
    return start.month() === end.month()
        ? `${start.format('MMM D')} - ${end.format('D')}`
        : `${start.format('MMM D')} - ${end.format('MMM D')}`;
}

const groupedTimeEntriesByWeek = computed((): WeekGroup[] => {
    const byDay = groupEntriesByDay(props.timeEntries);
    const currentWeekStart = getInitialWeekRange().start.format('YYYY-MM-DD');
    const weeks: Record<string, WeekGroup> = {};

    for (const [dayKey, dayEntries] of Object.entries(byDay)) {
        const weekStart = getWeekStartKey(dayKey);

        if (!weeks[weekStart]) {
            weeks[weekStart] = {
                weekStart,
                weekRangeDisplay: formatWeekRange(weekStart),
                isCurrentWeek: weekStart === currentWeekStart,
                weekTotal: 0,
                days: [],
            };
        }

        weeks[weekStart].weekTotal += sumDuration(dayEntries);
        weeks[weekStart].days.push({ date: dayKey, entries: dayEntries });
    }

    return Object.values(weeks)
        .map((week) => ({
            ...week,
            days: week.days.sort((a, b) => b.date.localeCompare(a.date)),
        }))
        .sort((a, b) => b.weekStart.localeCompare(a.weekStart));
});

/** Weeks collapsed by default except the current week. */
const collapsedWeeks = ref<Set<string>>(new Set());

watch(
    groupedTimeEntriesByWeek,
    (weeks) => {
        const next = new Set(collapsedWeeks.value);
        for (const week of weeks) {
            if (!next.has(week.weekStart) && !week.isCurrentWeek) {
                next.add(week.weekStart);
            }
            if (week.isCurrentWeek) {
                next.delete(week.weekStart);
            }
        }
        collapsedWeeks.value = next;
    },
    { immediate: true }
);

function isWeekCollapsed(weekStart: string): boolean {
    return collapsedWeeks.value.has(weekStart);
}

function toggleWeek(weekStart: string): void {
    const next = new Set(collapsedWeeks.value);
    if (next.has(weekStart)) {
        next.delete(weekStart);
    } else {
        next.add(weekStart);
    }
    collapsedWeeks.value = next;
}

function startTimeEntryFromExisting(entry: TimeEntry) {
    props.createTimeEntry({
        project_id: entry.project_id,
        task_id: entry.task_id,
        start: getDayJsInstance().utc().format(),
        end: null,
        billable: entry.billable,
        description: entry.description,
        tags: [...entry.tags],
    });
}

function sumDuration(timeEntries: TimeEntry[] | TimeEntriesGroupedByType[]) {
    return timeEntries.reduce((acc, entry) => acc + (entry?.duration ?? 0), 0);
}

function selectAllTimeEntries(value: TimeEntriesGroupedByType[]) {
    for (const timeEntry of value) {
        if ('timeEntries' in timeEntry) {
            for (const subTimeEntry of timeEntry.timeEntries) {
                selectedTimeEntries.value.push(subTimeEntry);
            }
        } else {
            selectedTimeEntries.value.push(timeEntry);
        }
    }
}

function unselectAllTimeEntries(value: TimeEntriesGroupedByType[]) {
    selectedTimeEntries.value = selectedTimeEntries.value.filter((timeEntry) => {
        return !value.find(
            (filterTimeEntry) =>
                filterTimeEntry.id === timeEntry.id ||
                filterTimeEntry.timeEntries?.find(
                    (subTimeEntry) => subTimeEntry.id === timeEntry.id
                )
        );
    });
}
</script>

<template>
    <div class="@container">
        <div v-for="week in groupedTimeEntriesByWeek" :key="week.weekStart">
            <TimeEntryWeekHeading
                :week-range-display="week.weekRangeDisplay"
                :is-current-week="week.isCurrentWeek"
                :duration="week.weekTotal"
                :collapsed="isWeekCollapsed(week.weekStart)"
                @toggle="toggleWeek(week.weekStart)" />
            <div v-show="!isWeekCollapsed(week.weekStart)">
            <div v-for="{ date, entries } in week.days" :key="date">
                <TimeEntryRowHeading
                    :date="date"
                    :duration="sumDuration(entries)"
                    :read-only="readOnly"
                    :checked="
                        entries.every((timeEntry: TimeEntry) =>
                            selectedTimeEntries.includes(timeEntry)
                        )
                    "
                    @select-all="selectAllTimeEntries(entries)"
                    @unselect-all="unselectAllTimeEntries(entries)"></TimeEntryRowHeading>
                <template v-for="entry in entries" :key="entry.id">
                    <TimeEntryAggregateRow
                        v-if="'timeEntries' in entry && entry.timeEntries.length > 1"
                        :create-project
                        :can-create-project
                        :enable-estimated-time
                        :selected-time-entries="selectedTimeEntries"
                        :create-client
                        :projects="projects"
                        :tasks="tasks"
                        :tags="tags"
                        :clients
                        :on-start-stop-click="startTimeEntryFromExisting"
                        :duplicate-time-entry="createTimeEntry"
                        :update-time-entries
                        :update-time-entry
                        :delete-time-entries
                        :create-tag
                        :currency="currency"
                        :organization-billable-rate="organizationBillableRate"
                        :read-only="readOnly"
                        :members="members"
                        :show-member="showMember"
                        :allow-member-assignment="allowMemberAssignment"
                        :time-entry="entry"
                        @selected="
                            (timeEntries: TimeEntry[]) => {
                                selectedTimeEntries = [...selectedTimeEntries, ...timeEntries];
                            }
                        "
                        @unselected="
                            (timeEntriesToUnselect: TimeEntry[]) => {
                                selectedTimeEntries = selectedTimeEntries.filter(
                                    (item: TimeEntry) =>
                                        !timeEntriesToUnselect.find(
                                            (filterEntry: TimeEntry) => filterEntry.id === item.id
                                        )
                                );
                            }
                        "></TimeEntryAggregateRow>
                    <TimeEntryRow
                        v-else
                        :create-client
                        :enable-estimated-time
                        :can-create-project
                        :create-project
                        :projects="projects"
                        :selected="
                            !!selectedTimeEntries.find(
                                (filterEntry: TimeEntry) => filterEntry.id === entry.id
                            )
                        "
                        :tasks="tasks"
                        :tags="tags"
                        :clients
                        :create-tag
                        :organization-billable-rate="organizationBillableRate"
                        :update-time-entry
                        :on-start-stop-click="() => startTimeEntryFromExisting(entry)"
                        :delete-time-entry="() => deleteTimeEntries([entry])"
                        :duplicate-time-entry="() => createTimeEntry(entry)"
                        :currency="currency"
                        :read-only="readOnly"
                        :members="members"
                        :show-member="showMember"
                        :allow-member-assignment="allowMemberAssignment"
                        :time-entry="entry.timeEntries[0]!"
                        @selected="selectedTimeEntries.push(entry)"
                        @unselected="
                            selectedTimeEntries = selectedTimeEntries.filter(
                                (item: TimeEntry) => item.id !== entry.id
                            )
                        "></TimeEntryRow>
                </template>
            </div>
            </div>
        </div>
    </div>
</template>

<style scoped></style>
