<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';
import { SecondaryButton } from '@/packages/ui/src';
import { ExclamationTriangleIcon } from '@heroicons/vue/20/solid';
import { computed, ref } from 'vue';
import { formatDuration, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import {
    useTimeEntryInvestigationMutations,
    useTimeEntryInvestigationQuery,
    type TimeEntryInvestigation,
} from '@/utils/useTimeEntryInvestigationQuery';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useNotificationsStore } from '@/utils/notification';

const { investigations, pendingInvestigations, isLoading, isError } =
    useTimeEntryInvestigationQuery();
const { updateReason } = useTimeEntryInvestigationMutations();
const { projects } = useProjectsQuery();
const notifications = useNotificationsStore();

const reasonDrafts = ref<Record<string, string>>({});
const savingId = ref<string | null>(null);
const showReviewed = ref(false);

const visibleEntries = computed(() => {
    if (showReviewed.value) {
        return investigations.value;
    }

    return pendingInvestigations.value;
});

function projectName(projectId: string | null): string {
    if (!projectId) {
        return '—';
    }

    return projects.value.find((project) => project.id === projectId)?.name ?? '—';
}

function formatRange(entry: TimeEntryInvestigation): string {
    const start = getLocalizedDayJs(entry.start);
    const end = entry.end ? getLocalizedDayJs(entry.end) : null;

    if (end) {
        return `${start.format('MMM D, YYYY HH:mm')} – ${end.format('HH:mm')}`;
    }

    return `${start.format('MMM D, YYYY HH:mm')} – running`;
}

function draftReason(entry: TimeEntryInvestigation): string {
    return reasonDrafts.value[entry.id] ?? entry.investigation_reason ?? '';
}

function setDraftReason(entryId: string, value: string) {
    reasonDrafts.value[entryId] = value;
}

async function saveReason(entry: TimeEntryInvestigation) {
    const reason = draftReason(entry).trim();

    if (reason.length < 3) {
        notifications.addNotification(
            'error',
            'Reason is required',
            'Enter at least 3 characters.'
        );
        return;
    }

    savingId.value = entry.id;

    try {
        await updateReason.mutateAsync({
            timeEntryId: entry.id,
            investigationReason: reason,
        });
        notifications.addNotification('success', 'Investigation saved');
    } catch {
        notifications.addNotification('error', 'Could not save investigation');
    } finally {
        savingId.value = null;
    }
}
</script>

<template>
    <AppLayout title="Time log investigation">
        <MainContainer>
            <PageTitle
                title="Time log investigation"
                :icon="ExclamationTriangleIcon"
                description="Entries longer than 8 hours are flagged here. Review each entry and record a reason." />

            <div class="mt-4 flex items-center justify-between gap-4">
                <p class="text-sm text-text-tertiary">
                    <template v-if="pendingInvestigations.length === 0">No pending investigations.</template>
                    <template v-else>
                        {{ pendingInvestigations.length }} pending investigation(s).
                    </template>
                </p>
                <label class="flex items-center gap-2 text-sm text-text-secondary">
                    <input v-model="showReviewed" type="checkbox" class="rounded border-default-border" />
                    Show reviewed
                </label>
            </div>

            <div v-if="isLoading" class="mt-10 flex justify-center">
                <LoadingSpinner />
            </div>

            <div v-else-if="isError" class="mt-10 text-center text-sm text-text-danger">
                Could not load investigations.
            </div>

            <div v-else-if="visibleEntries.length === 0" class="mt-10 text-center text-sm text-text-tertiary">
                No entries to show.
            </div>

            <div v-else class="mt-6 space-y-4">
                <div
                    v-for="entry in visibleEntries"
                    :key="entry.id"
                    class="rounded-lg border border-default-background-separator bg-card p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-medium text-text-primary">{{ entry.member_name }}</p>
                            <p class="text-sm text-text-tertiary">{{ entry.member_email }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-text-primary">{{ formatDuration(entry.duration) }}</p>
                            <p v-if="entry.is_running" class="text-xs font-medium text-amber-600">Still running</p>
                            <p
                                v-else-if="entry.investigation_reviewed_at"
                                class="text-xs font-medium text-emerald-600">
                                Reviewed
                            </p>
                            <p v-else class="text-xs font-medium text-amber-600">Needs review</p>
                        </div>
                    </div>

                    <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-text-tertiary">When</dt>
                            <dd class="text-text-secondary">{{ formatRange(entry) }}</dd>
                        </div>
                        <div>
                            <dt class="text-text-tertiary">Project</dt>
                            <dd class="text-text-secondary">{{ projectName(entry.project_id) }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-text-tertiary">Description</dt>
                            <dd class="text-text-secondary">{{ entry.description || '—' }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4">
                        <label
                            class="mb-1 block text-sm font-medium text-text-secondary"
                            :for="`reason-${entry.id}`">
                            Reason
                        </label>
                        <textarea
                            :id="`reason-${entry.id}`"
                            :value="draftReason(entry)"
                            rows="3"
                            class="w-full rounded-md border border-default-border bg-input px-3 py-2 text-sm text-text-primary"
                            placeholder="Why was this entry longer than 8 hours?"
                            @input="setDraftReason(entry.id, ($event.target as HTMLTextAreaElement).value)" />
                        <div class="mt-2 flex justify-end">
                            <SecondaryButton
                                :disabled="savingId === entry.id"
                                @click="saveReason(entry)">
                                {{ savingId === entry.id ? 'Saving…' : 'Save reason' }}
                            </SecondaryButton>
                        </div>
                    </div>
                </div>
            </div>
        </MainContainer>
    </AppLayout>
</template>
