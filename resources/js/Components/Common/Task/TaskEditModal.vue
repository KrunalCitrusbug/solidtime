<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { ref, computed } from 'vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useFocus } from '@vueuse/core';
import { useTasksStore } from '@/utils/useTasks';
import type { Task, UpdateTaskBody } from '@/packages/api/src';
import EstimatedTimeSection from '@/packages/ui/src/EstimatedTimeSection.vue';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { Field, FieldGroup, FieldLabel } from '@/packages/ui/src/field';
import { Button } from '@/packages/ui/src/Buttons';
import { ChevronDown } from 'lucide-vue-next';
import { UserGroupIcon } from '@heroicons/vue/20/solid';
import MemberMultiselectDropdown from '@/Components/Common/Member/MemberMultiselectDropdown.vue';
import { useMembersQuery } from '@/utils/useMembersQuery';
import { useProjectMembersQuery } from '@/utils/useProjectMembersQuery';

const { updateTask } = useTasksStore();
const show = defineModel('show', { default: false });
const saving = ref(false);

const props = defineProps<{
    task: Task;
}>();

const taskWithVisibility = props.task as Task & { is_public?: boolean; member_ids?: string[] };

const taskBody = ref<UpdateTaskBody>({
    name: props.task.name,
    estimated_time: props.task.estimated_time,
});
const isPublic = ref(taskWithVisibility.is_public ?? true);
const selectedMemberIds = ref<string[]>([...(taskWithVisibility.member_ids ?? [])]);

const { members: allMembers } = useMembersQuery();
const { projectMembers } = useProjectMembersQuery(computed(() => props.task.project_id));

const projectMemberList = computed(() => {
    const ids = new Set(projectMembers.value.map((pm) => pm.member_id));
    return allMembers.value.filter((m) => ids.has(m.id));
});

async function submit() {
    await updateTask(props.task.id, {
        ...taskBody.value,
        is_public: isPublic.value,
        member_ids: isPublic.value ? [] : selectedMemberIds.value,
    });
    show.value = false;
}

const taskNameInput = ref<HTMLInputElement | null>(null);

useFocus(taskNameInput, { initialValue: true });
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>
            <div class="flex space-x-2">
                <span> Update Task </span>
            </div>
        </template>

        <template #content>
            <FieldGroup>
                <Field>
                    <FieldLabel for="taskName">Task name</FieldLabel>
                    <TextInput
                        id="taskName"
                        ref="taskNameInput"
                        v-model="taskBody.name"
                        type="text"
                        placeholder="Task Name"
                        class="block w-full"
                        required
                        autocomplete="taskName"
                        @keydown.enter="submit()" />
                </Field>
                <EstimatedTimeSection
                    v-if="isAllowedToPerformPremiumAction()"
                    v-model="taskBody.estimated_time"
                    @submit="submit()"></EstimatedTimeSection>
                <Field class="w-auto">
                    <FieldLabel for="visibility">Visibility</FieldLabel>
                    <div class="flex items-center rounded-lg border border-input-border overflow-hidden w-min">
                        <button
                            type="button"
                            class="px-3 py-1.5 text-sm transition"
                            :class="
                                isPublic
                                    ? 'bg-secondary text-text-primary font-medium'
                                    : 'text-text-secondary hover:text-text-primary'
                            "
                            @click="isPublic = true">
                            Public
                        </button>
                        <button
                            type="button"
                            class="px-3 py-1.5 text-sm transition border-l border-input-border"
                            :class="
                                !isPublic
                                    ? 'bg-secondary text-text-primary font-medium'
                                    : 'text-text-secondary hover:text-text-primary'
                            "
                            @click="isPublic = false">
                            Private
                        </button>
                    </div>
                    <p class="text-xs text-text-tertiary pt-1">
                        {{
                            isPublic
                                ? 'Anyone with access to the project can use this task.'
                                : 'Only the members you select below can access this task.'
                        }}
                    </p>
                </Field>
                <Field v-if="!isPublic" class="w-auto">
                    <FieldLabel :icon="UserGroupIcon" for="members">Members with access</FieldLabel>
                    <p v-if="projectMemberList.length === 0" class="text-xs text-text-tertiary py-1">
                        No project members found. Add members to the project first.
                    </p>
                    <MemberMultiselectDropdown
                        v-else
                        v-model="selectedMemberIds"
                        :members="projectMemberList">
                        <template #trigger>
                            <Button variant="input" class="w-full justify-between">
                                <span class="truncate">
                                    {{
                                        selectedMemberIds.length > 0
                                            ? `${selectedMemberIds.length} member(s) selected`
                                            : 'Select members'
                                    }}
                                </span>
                                <ChevronDown class="w-4 h-4 text-icon-default" />
                            </Button>
                        </template>
                    </MemberMultiselectDropdown>
                </Field>
            </FieldGroup>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false"> Cancel </SecondaryButton>
            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                Update Task
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
