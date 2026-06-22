<script setup lang="ts">
import ProjectMoreOptionsDropdown from '@/Components/Common/Project/ProjectMoreOptionsDropdown.vue';
import type { Project } from '@/packages/api/src';
import { computed, ref, inject, type ComputedRef } from 'vue';
import { CheckCircleIcon, ArchiveBoxIcon } from '@heroicons/vue/24/outline';
import {
    PencilSquareIcon,
    ArchiveBoxIcon as ArchiveBoxIconSolid,
    TrashIcon,
    GlobeAltIcon,
    LockClosedIcon,
} from '@heroicons/vue/20/solid';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useProjectsStore } from '@/utils/useProjects';
import TableRow from '@/Components/TableRow.vue';
import ProjectEditModal from '@/Components/Common/Project/ProjectEditModal.vue';
import { formatCents } from '@/packages/ui/src/utils/money';
import { getOrganizationCurrencyString } from '@/utils/money';
import { canUpdateProjects, canDeleteProjects } from '@/utils/permissions';
import type { Organization } from '@/packages/api/src';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/packages/ui/src';

const { clients } = useClientsQuery();
const { tasks } = useTasksQuery();

const props = defineProps<{
    project: Project;
    showBillableRate: boolean;
}>();

const client = computed(() => {
    return clients.value.find((client) => client.id === props.project.client_id);
});

const projectTasksCount = computed(() => {
    return tasks.value.filter((task) => task.project_id === props.project.id).length;
});

const publicTasksCount = computed(() => {
    return tasks.value.filter((task) => {
        if (task.project_id !== props.project.id) return false;
        return (task as { is_public?: boolean }).is_public !== false;
    }).length;
});

const privateTasksCount = computed(() => {
    return tasks.value.filter((task) => {
        if (task.project_id !== props.project.id) return false;
        return (task as { is_public?: boolean }).is_public === false;
    }).length;
});

function deleteProject() {
    useProjectsStore().deleteProject(props.project.id);
}

function archiveProject() {
    useProjectsStore().updateProject(props.project.id, {
        ...props.project,
        is_archived: !props.project.is_archived,
    });
}

const organization = inject<ComputedRef<Organization>>('organization');

const billableRateInfo = computed(() => {
    if (props.project.is_billable) {
        if (props.project.billable_rate) {
            return formatCents(
                props.project.billable_rate,
                getOrganizationCurrencyString(),
                organization?.value?.currency_format,
                organization?.value?.currency_symbol,
                organization?.value?.number_format
            );
        } else {
            return 'Default Rate';
        }
    }
    return null;
});

const showEditProjectModal = ref(false);
</script>

<template>
    <ProjectEditModal
        v-model:show="showEditProjectModal"
        :original-project="project"></ProjectEditModal>
    <ContextMenu>
        <ContextMenuTrigger as-child>
            <TableRow :href="route('projects.show', { project: project.id })">
                <div
                    class="whitespace-nowrap min-w-0 flex items-center space-x-5 3xl:pl-12 py-4 pr-3 text-sm font-medium text-text-primary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12">
                    <div
                        :style="{
                            backgroundColor: project.color,
                            boxShadow: `var(--tw-ring-inset) 0 0 0 calc(4px + var(--tw-ring-offset-width)) ${project.color}30`,
                        }"
                        class="w-3 h-3 rounded-full"></div>
                    <span class="overflow-ellipsis overflow-hidden">
                        {{ project.name }}
                    </span>
                    <span class="text-text-secondary"> {{ projectTasksCount }} Tasks </span>
                </div>
                <div class="whitespace-nowrap min-w-0 px-3 py-4 text-sm text-text-primary">
                    <div v-if="project.client_id" class="overflow-ellipsis overflow-hidden">
                        {{ client?.name }}
                    </div>
                    <div v-else class="text-text-tertiary">No client</div>
                </div>
                <div
                    v-if="showBillableRate"
                    class="whitespace-nowrap px-3 py-4 text-sm text-text-primary">
                    <span v-if="billableRateInfo">{{ billableRateInfo }}</span>
                    <span v-else class="text-text-tertiary">--</span>
                </div>
                <div
                    class="whitespace-nowrap px-3 py-4 text-sm text-text-primary flex space-x-1.5 items-center font-medium">
                    <template v-if="project.is_archived">
                        <ArchiveBoxIcon class="w-4 text-icon-default"></ArchiveBoxIcon>
                        <span>Archived</span>
                    </template>
                    <template v-else>
                        <CheckCircleIcon class="w-4 text-icon-default"></CheckCircleIcon>
                        <span>Active</span>
                    </template>
                </div>
                <div
                    class="whitespace-nowrap px-3 py-4 text-sm text-text-primary flex space-x-1.5 items-center font-medium">
                    <template v-if="project.is_public">
                        <GlobeAltIcon class="w-4 text-icon-default flex-shrink-0"></GlobeAltIcon>
                        <span>All Members</span>
                    </template>
                    <template v-else>
                        <LockClosedIcon class="w-4 text-icon-default flex-shrink-0"></LockClosedIcon>
                        <span>Members Only</span>
                    </template>
                </div>
                <div class="whitespace-nowrap px-3 py-4 text-sm text-text-primary">
                    <template v-if="projectTasksCount === 0">
                        <span class="text-text-tertiary">No tasks</span>
                    </template>
                    <template v-else-if="privateTasksCount === 0">
                        <span class="text-green-600 dark:text-green-400">All Public</span>
                    </template>
                    <template v-else-if="publicTasksCount === 0">
                        <span class="text-orange-500">All Private</span>
                    </template>
                    <template v-else>
                        <span class="text-green-600 dark:text-green-400">{{ publicTasksCount }} Public</span>
                        <span class="text-text-tertiary"> / </span>
                        <span class="text-orange-500">{{ privateTasksCount }} Private</span>
                    </template>
                </div>
                <div
                    class="relative whitespace-nowrap flex items-center pl-3 text-right text-sm font-medium pr-4 sm:pr-6 lg:pr-8 3xl:pr-12">
                    <ProjectMoreOptionsDropdown
                        :project="project"
                        @edit="showEditProjectModal = true"
                        @archive="archiveProject"
                        @delete="deleteProject"></ProjectMoreOptionsDropdown>
                </div>
            </TableRow>
        </ContextMenuTrigger>
        <ContextMenuContent class="min-w-[160px]">
            <ContextMenuItem
                v-if="canUpdateProjects()"
                class="space-x-3"
                @select="showEditProjectModal = true">
                <PencilSquareIcon class="w-4 h-4 text-icon-default" />
                <span>Edit</span>
            </ContextMenuItem>
            <ContextMenuItem
                v-if="canUpdateProjects()"
                class="space-x-3"
                @select="archiveProject()">
                <ArchiveBoxIconSolid class="w-4 h-4 text-icon-default" />
                <span>{{ project.is_archived ? 'Unarchive' : 'Archive' }}</span>
            </ContextMenuItem>
            <ContextMenuSeparator v-if="canDeleteProjects()" />
            <ContextMenuItem
                v-if="canDeleteProjects()"
                class="space-x-3 text-destructive"
                @select="deleteProject()">
                <TrashIcon class="w-4 h-4 text-icon-default" />
                <span>Delete</span>
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>
</template>

<style scoped></style>
