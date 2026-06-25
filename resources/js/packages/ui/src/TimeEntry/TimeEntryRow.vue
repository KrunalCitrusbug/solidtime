<script setup lang="ts">
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import TimeTrackerStartStop from '@/packages/ui/src/TimeTrackerStartStop.vue';
import TimeEntryRangeSelector from '@/packages/ui/src/TimeEntry/TimeEntryRangeSelector.vue';
import type {
    Client,
    CreateClientBody,
    CreateProjectBody,
    Member,
    Project,
    Tag,
    Task,
    TimeEntry,
} from '@/packages/api/src';
import TimeEntryDescriptionInput from '@/packages/ui/src/TimeEntry/TimeEntryDescriptionInput.vue';
import TimeEntryRowTagDropdown from '@/packages/ui/src/TimeEntry/TimeEntryRowTagDropdown.vue';
import TimeEntryRowDurationInput from '@/packages/ui/src/TimeEntry/TimeEntryRowDurationInput.vue';
import TimeEntryMoreOptionsDropdown from '@/packages/ui/src/TimeEntry/TimeEntryMoreOptionsDropdown.vue';
import { TimeEntryEditModal } from '@/packages/ui/src';
import BillableToggleButton from '@/packages/ui/src/Input/BillableToggleButton.vue';
import { computed, inject, ref, type ComputedRef } from 'vue';
import TimeTrackerProjectTaskDropdown from '@/packages/ui/src/TimeTracker/TimeTrackerProjectTaskDropdown.vue';
import {
    Checkbox,
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/packages/ui/src';
import { PlayIcon, PencilIcon, DocumentDuplicateIcon, TrashIcon } from '@heroicons/vue/20/solid';
import {
    formatHumanReadableDuration,
    formatStartEnd,
} from '@/packages/ui/src/utils/time';
import type { Organization } from '@/packages/api/src';

const organization = inject<ComputedRef<Organization>>('organization');

const props = defineProps<{
    timeEntry: TimeEntry;
    indent?: boolean;
    projects: Project[];
    tasks: Task[];
    tags: Tag[];
    clients: Client[];
    members?: Member[];
    createTag: (name: string) => Promise<Tag | undefined>;
    createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
    createClient: (client: CreateClientBody) => Promise<Client | undefined>;
    onStartStopClick: () => void;
    deleteTimeEntry: () => void;
    duplicateTimeEntry?: () => void;
    updateTimeEntry: (timeEntry: TimeEntry) => void;
    currency: string;
    organizationBillableRate: number | null;
    showMember?: boolean;
    showDate?: boolean;
    selected?: boolean;
    canCreateProject: boolean;
    enableEstimatedTime: boolean;
    isReport?: boolean;
    readOnly?: boolean;
    allowMemberAssignment?: boolean;
}>();

const emit = defineEmits<{ selected: []; unselected: [] }>();

const showEditModal = ref(false);

function updateTimeEntryDescription(description: string) {
    props.updateTimeEntry({ ...props.timeEntry, description });
}

function updateTimeEntryTags(tags: string[]) {
    props.updateTimeEntry({ ...props.timeEntry, tags });
}

function updateTimeEntryBillable(billable: boolean) {
    props.updateTimeEntry({ ...props.timeEntry, billable });
}

function updateStartEndTime(start: string, end: string | null) {
    props.updateTimeEntry({ ...props.timeEntry, start, end });
}

function updateProjectAndTask(projectId: string, taskId: string) {
    const project = props.projects.find((p) => p.id === projectId);
    props.updateTimeEntry({
        ...props.timeEntry,
        project_id: projectId,
        task_id: taskId,
        billable: project ? project.is_billable : props.timeEntry.billable,
    });
}

const memberName = computed(() => {
    if (props.members) {
        const member = props.members.find((member) => member.user_id === props.timeEntry.user_id);
        if (member) {
            return member.name;
        }
    }
    return '';
});

function onSelectChange(checked: boolean) {
    if (checked) {
        emit('selected');
    } else {
        emit('unselected');
    }
}

function handleEdit() {
    showEditModal.value = true;
}

async function handleUpdateTimeEntry(updatedEntry: TimeEntry) {
    props.updateTimeEntry(updatedEntry);
    showEditModal.value = false;
}

async function handleDeleteTimeEntry() {
    props.deleteTimeEntry();
    showEditModal.value = false;
}
</script>

<template>
    <ContextMenu>
        <ContextMenuTrigger as-child>
            <div
                class="border-b border-default-background-separator transition min-w-0 bg-row-background"
                data-testid="time_entry_row">
                <MainContainer class="min-w-0">
                    <div class="@xl:flex py-2 min-w-0 items-center justify-between group">
                        <!-- Desktop layout -->
                        <div class="hidden @lg:flex items-center min-w-0">
                            <Checkbox
                                v-if="!readOnly"
                                :checked="selected"
                                @update:checked="onSelectChange" />
                            <div v-if="indent === true" class="w-10 h-7"></div>
                            <TimeEntryDescriptionInput
                                class="min-w-0 mr-4 shrink"
                                :read-only="readOnly"
                                :model-value="timeEntry.description"
                                @changed="updateTimeEntryDescription"></TimeEntryDescriptionInput>
                            <TimeTrackerProjectTaskDropdown
                                v-if="!readOnly"
                                class="min-w-0 shrink"
                                :create-project
                                :create-client
                                :can-create-project
                                :clients
                                :projects="projects"
                                :tasks="tasks"
                                :project="timeEntry.project_id"
                                :currency="currency"
                                :organization-billable-rate="organizationBillableRate"
                                :enable-estimated-time
                                :task="timeEntry.task_id"
                                @changed="updateProjectAndTask"></TimeTrackerProjectTaskDropdown>
                        </div>
                        <div class="hidden @lg:flex items-center space-x-1 @lg:space-x-2 shrink-0">
                            <div v-if="showMember && members" class="text-sm px-2">
                                {{ memberName }}
                            </div>
                            <template v-if="!readOnly">
                            <TimeEntryRowTagDropdown
                                :create-tag
                                :tags="tags"
                                :model-value="timeEntry.tags"
                                @changed="updateTimeEntryTags"></TimeEntryRowTagDropdown>
                            <BillableToggleButton
                                :model-value="timeEntry.billable"
                                size="small"
                                faded
                                @changed="updateTimeEntryBillable"></BillableToggleButton>
                            <div class="flex-1">
                                <TimeEntryRangeSelector
                                    :start="timeEntry.start"
                                    :end="timeEntry.end"
                                    :show-date
                                    @changed="updateStartEndTime"></TimeEntryRangeSelector>
                            </div>
                            <TimeEntryRowDurationInput
                                :start="timeEntry.start"
                                :end="timeEntry.end"
                                :is-report="props.isReport"
                                @changed="updateStartEndTime"></TimeEntryRowDurationInput>
                            </template>
                            <div v-else class="flex items-center space-x-2 text-sm text-text-secondary">
                                <span>{{
                                    formatStartEnd(
                                        timeEntry.start,
                                        timeEntry.end,
                                        organization?.time_format
                                    )
                                }}</span>
                                <span class="text-text-primary font-medium">{{
                                    formatHumanReadableDuration(
                                        timeEntry.duration ?? 0,
                                        organization?.interval_format,
                                        organization?.number_format
                                    )
                                }}</span>
                            </div>
                            <TimeTrackerStartStop
                                :active="!!(timeEntry.start && !timeEntry.end)"
                                variant="secondary"
                                class="opacity-60 flex focus-visible:opacity-100 group-hover:opacity-100"
                                @changed="onStartStopClick"></TimeTrackerStartStop>
                            <TimeEntryMoreOptionsDropdown
                                v-if="!readOnly"
                                @edit="handleEdit"
                                :show-duplicate="!!duplicateTimeEntry"
                                @duplicate="duplicateTimeEntry"
                                @delete="deleteTimeEntry"></TimeEntryMoreOptionsDropdown>
                        </div>
                        <!-- Mobile layout -->
                        <div class="@lg:hidden">
                            <!-- First row: description + duration -->
                            <div class="flex items-center justify-between min-w-0">
                                <TimeEntryDescriptionInput
                                    class="min-w-0 flex-1"
                                    :read-only="readOnly"
                                    :model-value="timeEntry.description"
                                    @changed="
                                        updateTimeEntryDescription
                                    "></TimeEntryDescriptionInput>
                                <TimeEntryRowDurationInput
                                    v-if="!readOnly"
                                    :start="timeEntry.start"
                                    :end="timeEntry.end"
                                    :is-report="props.isReport"
                                    @changed="updateStartEndTime"></TimeEntryRowDurationInput>
                                <span
                                    v-else
                                    class="text-text-primary min-w-[80px] px-1.5 py-1.5 text-sm font-medium text-right">
                                    {{
                                        formatHumanReadableDuration(
                                            timeEntry.duration ?? 0,
                                            organization?.interval_format,
                                            organization?.number_format
                                        )
                                    }}
                                </span>
                            </div>
                            <!-- Second row: project/task - tags - billable - start - more -->
                            <div class="flex items-center justify-between mt-1">
                                <TimeTrackerProjectTaskDropdown
                                    v-if="!readOnly"
                                    class="min-w-0"
                                    :create-project
                                    :create-client
                                    :can-create-project
                                    :clients
                                    :projects="projects"
                                    :tasks="tasks"
                                    :project="timeEntry.project_id"
                                    :currency="currency"
                                    :organization-billable-rate="organizationBillableRate"
                                    :enable-estimated-time
                                    :task="timeEntry.task_id"
                                    @changed="
                                        updateProjectAndTask
                                    "></TimeTrackerProjectTaskDropdown>
                                <div class="flex items-center shrink-0">
                                    <template v-if="!readOnly">
                                    <TimeEntryRowTagDropdown
                                        :create-tag
                                        :tags="tags"
                                        :model-value="timeEntry.tags"
                                        compact
                                        @changed="updateTimeEntryTags"></TimeEntryRowTagDropdown>
                                    <BillableToggleButton
                                        :model-value="timeEntry.billable"
                                        size="small"
                                        @changed="updateTimeEntryBillable"></BillableToggleButton>
                                    </template>
                                    <TimeTrackerStartStop
                                        :active="!!(timeEntry.start && !timeEntry.end)"
                                        variant="secondary"
                                        class="ml-2"
                                        @changed="onStartStopClick"></TimeTrackerStartStop>
                                    <TimeEntryMoreOptionsDropdown
                                        v-if="!readOnly"
                                        @edit="handleEdit"
                                        :show-duplicate="!!duplicateTimeEntry"
                                        @duplicate="duplicateTimeEntry"
                                        @delete="deleteTimeEntry"></TimeEntryMoreOptionsDropdown>
                                </div>
                            </div>
                        </div>
                    </div>
                </MainContainer>
            </div>
        </ContextMenuTrigger>
        <ContextMenuContent class="min-w-[160px]">
            <ContextMenuItem class="space-x-3" @select="onStartStopClick()">
                <PlayIcon class="w-4 h-4 text-icon-default" />
                <span>Continue</span>
            </ContextMenuItem>
            <template v-if="!readOnly">
            <ContextMenuItem class="space-x-3" @select="handleEdit()">
                <PencilIcon class="w-4 h-4 text-icon-default" />
                <span>Edit</span>
            </ContextMenuItem>
            <ContextMenuItem class="space-x-3" @select="duplicateTimeEntry?.()">
                <DocumentDuplicateIcon class="w-4 h-4 text-icon-default" />
                <span>Duplicate</span>
            </ContextMenuItem>
            <ContextMenuSeparator />
            <ContextMenuItem class="space-x-3 text-destructive" @select="deleteTimeEntry()">
                <TrashIcon class="w-4 h-4 text-icon-default" />
                <span>Delete</span>
            </ContextMenuItem>
            </template>
        </ContextMenuContent>
    </ContextMenu>

    <TimeEntryEditModal
        v-if="showEditModal"
        v-model:show="showEditModal"
        :time-entry="timeEntry"
        :enable-estimated-time="enableEstimatedTime"
        :update-time-entry="handleUpdateTimeEntry"
        :delete-time-entry="handleDeleteTimeEntry"
        :create-client="createClient"
        :create-project="createProject"
        :create-tag="createTag"
        :tags="tags"
        :projects="projects"
        :tasks="tasks"
        :clients="clients"
        :currency="currency"
        :organization-billable-rate="organizationBillableRate"
        :can-create-project="canCreateProject"
        :allow-member-assignment="allowMemberAssignment"
        :members="members" />
</template>

<style scoped></style>
