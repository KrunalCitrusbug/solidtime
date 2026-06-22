<script setup lang="ts">
import type { Task } from '@/packages/api/src';
import { CheckCircleIcon, GlobeAltIcon, LockClosedIcon } from '@heroicons/vue/20/solid';
import { useTasksStore } from '@/utils/useTasks';
import TaskMoreOptionsDropdown from '@/Components/Common/Task/TaskMoreOptionsDropdown.vue';
import TableRow from '@/Components/TableRow.vue';
import { canDeleteTasks } from '@/utils/permissions';
import TaskEditModal from '@/Components/Common/Task/TaskEditModal.vue';
import { ref, computed } from 'vue';
import { useMembersQuery } from '@/utils/useMembersQuery';

const props = defineProps<{
    task: Task;
}>();

const { members } = useMembersQuery();

type TaskWithVisibility = Task & { is_public?: boolean; member_ids?: string[] };

const taskV = computed(() => props.task as TaskWithVisibility);
const isPublic = computed(() => taskV.value.is_public !== false);

const accessLabel = computed(() => {
    if (isPublic.value) return null;
    const ids = taskV.value.member_ids ?? [];
    if (ids.length === 0) return 'Members Only';
    const names = ids
        .map((id) => members.value.find((m) => m.id === id)?.name ?? null)
        .filter(Boolean) as string[];
    if (names.length <= 3) return names.join(', ');
    return `${names.slice(0, 2).join(', ')} +${names.length - 2} more`;
});

function deleteTask() {
    useTasksStore().deleteTask(props.task.id);
}

function markTaskAsDone() {
    useTasksStore().updateTask(props.task.id, {
        ...props.task,
        is_done: !props.task.is_done,
    });
}

const showTaskEditModal = ref(false);
</script>

<template>
    <TableRow>
        <div
            class="whitespace-nowrap min-w-0 flex items-center space-x-5 3xl:pl-12 py-4 pr-3 text-sm font-medium text-text-primary pl-4 sm:pl-6 lg:pl-8 3xl:pl-12">
            <span class="overflow-ellipsis overflow-hidden">
                {{ task.name }}
            </span>
        </div>
        <div
            class="whitespace-nowrap px-3 py-4 text-sm text-text-primary flex items-center gap-1.5 font-medium min-w-0">
            <template v-if="isPublic">
                <GlobeAltIcon class="w-4 h-4 text-icon-default flex-shrink-0" />
                <span>All Members</span>
            </template>
            <template v-else>
                <LockClosedIcon class="w-4 h-4 text-icon-default flex-shrink-0" />
                <span class="truncate text-text-secondary">{{ accessLabel }}</span>
            </template>
        </div>
        <div
            class="whitespace-nowrap px-3 py-4 text-sm text-text-secondary flex space-x-1 items-center font-medium">
            <template v-if="task.is_done">
                <CheckCircleIcon class="w-5"></CheckCircleIcon>
                <span>Done</span>
            </template>
            <template v-else>
                <span>Active</span>
            </template>
        </div>
        <div
            class="relative whitespace-nowrap flex items-center pl-3 text-right text-sm font-medium sm:pr-0 pr-4 sm:pr-6 lg:pr-8 3xl:pr-12">
            <TaskMoreOptionsDropdown
                v-if="canDeleteTasks()"
                :task="task"
                @done="markTaskAsDone"
                @edit="showTaskEditModal = true"
                @delete="deleteTask"></TaskMoreOptionsDropdown>
        </div>
        <TaskEditModal v-model:show="showTaskEditModal" :task="task"></TaskEditModal>
    </TableRow>
</template>

<style scoped></style>
