<script setup lang="ts">
import { CheckCircleIcon, UserGroupIcon } from '@heroicons/vue/20/solid';
import { FolderIcon } from '@heroicons/vue/16/solid';
import TaskMultiselectDropdown from '@/Components/Common/Task/TaskMultiselectDropdown.vue';
import ClientMultiselectDropdown from '@/Components/Common/Client/ClientMultiselectDropdown.vue';
import MemberMultiselectDropdown from '@/Components/Common/Member/MemberMultiselectDropdown.vue';
import ReportingFilterBadge from '@/Components/Common/Reporting/ReportingFilterBadge.vue';
import ProjectMultiselectDropdown from '@/Components/Common/Project/ProjectMultiselectDropdown.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import DateRangePicker from '@/packages/ui/src/Input/DateRangePicker.vue';
import { XMarkIcon } from '@heroicons/vue/16/solid';
import { computed } from 'vue';
import { canFilterReportsByMember } from '@/utils/permissions';

type TimeEntryRoundingType = 'up' | 'down' | 'nearest';

const selectedMembers = defineModel<string[]>('selectedMembers', { required: true });
const selectedProjects = defineModel<string[]>('selectedProjects', { required: true });
const selectedTasks = defineModel<string[]>('selectedTasks', { required: true });
const selectedClients = defineModel<string[]>('selectedClients', { required: true });
defineModel<string[]>('selectedTags', { required: true });
defineModel<'true' | 'false' | null>('billable', { required: true });
defineModel<boolean>('roundingEnabled', { required: true });
defineModel<TimeEntryRoundingType>('roundingType', { required: true });
defineModel<number>('roundingMinutes', { required: true });
const startDate = defineModel<string>('startDate', { required: true });
const endDate = defineModel<string>('endDate', { required: true });

const emit = defineEmits<{
    submit: [];
}>();

const showMemberFilter = computed(() => canFilterReportsByMember());

const hasActiveFilters = computed(
    () =>
        (showMemberFilter.value && selectedMembers.value.length > 0) ||
        selectedProjects.value.length > 0 ||
        selectedTasks.value.length > 0 ||
        selectedClients.value.length > 0
);

function clearFilters() {
    if (showMemberFilter.value) {
        selectedMembers.value = [];
    }
    selectedProjects.value = [];
    selectedTasks.value = [];
    selectedClients.value = [];
    emit('submit');
}
</script>

<template>
    <div class="py-2.5 w-full border-b border-default-background-separator">
        <MainContainer class="sm:flex space-y-4 sm:space-y-0 justify-between">
            <div class="flex flex-wrap items-center space-y-2 sm:space-y-0 space-x-3">
                <div class="text-sm font-medium">Filters</div>
                <MemberMultiselectDropdown
                    v-if="showMemberFilter"
                    v-model="selectedMembers"
                    @submit="emit('submit')">
                    <template #trigger>
                        <ReportingFilterBadge
                            :count="selectedMembers.length"
                            :active="selectedMembers.length > 0"
                            title="Members"
                            :icon="UserGroupIcon" />
                    </template>
                </MemberMultiselectDropdown>
                <ProjectMultiselectDropdown v-model="selectedProjects" @submit="emit('submit')">
                    <template #trigger>
                        <ReportingFilterBadge
                            :count="selectedProjects.length"
                            :active="selectedProjects.length > 0"
                            title="Projects"
                            :icon="FolderIcon" />
                    </template>
                </ProjectMultiselectDropdown>
                <TaskMultiselectDropdown v-model="selectedTasks" @submit="emit('submit')">
                    <template #trigger>
                        <ReportingFilterBadge
                            :count="selectedTasks.length"
                            :active="selectedTasks.length > 0"
                            title="Tasks"
                            :icon="CheckCircleIcon" />
                    </template>
                </TaskMultiselectDropdown>
                <ClientMultiselectDropdown v-model="selectedClients" @submit="emit('submit')">
                    <template #trigger>
                        <ReportingFilterBadge
                            :count="selectedClients.length"
                            :active="selectedClients.length > 0"
                            title="Clients"
                            :icon="FolderIcon" />
                    </template>
                </ClientMultiselectDropdown>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="flex items-center gap-1 text-sm text-text-secondary hover:text-text-primary transition px-2 py-1.5"
                    @click="clearFilters">
                    <XMarkIcon class="w-4 h-4" />
                    <span>Clear filters</span>
                </button>
            </div>
            <div>
                <DateRangePicker
                    v-model:start="startDate"
                    v-model:end="endDate"
                    @submit="emit('submit')" />
            </div>
        </MainContainer>
    </div>
</template>
