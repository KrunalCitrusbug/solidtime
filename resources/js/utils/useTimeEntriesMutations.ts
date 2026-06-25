import { useMutation, useQueryClient } from '@tanstack/vue-query';
import {
    api,
    type CreateTimeEntryBody,
    type TimeEntry,
    type UpdateMultipleTimeEntriesChangeset,
} from '@/packages/api/src';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';

export type CreateTimeEntryInput = Omit<CreateTimeEntryBody, 'member_id'> & { member_id?: string };
export type UpdateTimeEntryInput = TimeEntry & { member_id?: string };

export function useTimeEntriesMutations() {
    const queryClient = useQueryClient();
    const { handleApiRequestNotifications } = useNotificationsStore();

    const { mutateAsync: createTimeEntry } = useMutation({
        mutationFn: async (timeEntry: CreateTimeEntryInput) => {
            const organizationId = getCurrentOrganizationId();
            const memberId = timeEntry.member_id ?? getCurrentMembershipId();
            if (organizationId && memberId !== undefined) {
                const { member_id: _ignored, ...entryFields } = timeEntry;
                const newTimeEntry = {
                    ...entryFields,
                    member_id: memberId,
                } as CreateTimeEntryBody;

                return await handleApiRequestNotifications(
                    () =>
                        api.createTimeEntry(newTimeEntry, {
                            params: {
                                organization: organizationId,
                            },
                        }),
                    'Time entry created successfully',
                    'Failed to create time entry'
                );
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
        },
    });

    const { mutateAsync: updateTimeEntry } = useMutation({
        mutationFn: async (timeEntry: UpdateTimeEntryInput) => {
            const organizationId = getCurrentOrganizationId();
            if (organizationId) {
                const body: {
                    project_id: string | null;
                    task_id: string | null;
                    start: string;
                    end: string | null;
                    billable: boolean;
                    description: string | null;
                    tags: string[];
                    member_id?: string;
                } = {
                    project_id: timeEntry.project_id,
                    task_id: timeEntry.task_id,
                    start: timeEntry.start,
                    end: timeEntry.end,
                    billable: timeEntry.billable,
                    description: timeEntry.description,
                    tags: timeEntry.tags,
                };
                if (timeEntry.member_id) {
                    body.member_id = timeEntry.member_id;
                }

                return await handleApiRequestNotifications(
                    () =>
                        api.updateTimeEntry(body, {
                            params: {
                                organization: organizationId,
                                timeEntry: timeEntry.id,
                            },
                        }),
                    'Time entry updated successfully',
                    'Failed to update time entry'
                );
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
        },
    });

    const { mutateAsync: updateTimeEntries } = useMutation({
        mutationFn: async ({
            ids,
            changes,
        }: {
            ids: string[];
            changes: UpdateMultipleTimeEntriesChangeset;
        }) => {
            const organizationId = getCurrentOrganizationId();
            if (organizationId) {
                return await handleApiRequestNotifications(
                    () =>
                        api.updateMultipleTimeEntries(
                            {
                                ids: ids,
                                changes: changes,
                            },
                            {
                                params: {
                                    organization: organizationId,
                                },
                            }
                        ),
                    'Time entries updated successfully',
                    'Failed to update time entries'
                );
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
        },
    });

    const { mutateAsync: deleteTimeEntry } = useMutation({
        mutationFn: async (timeEntryId: string) => {
            const organizationId = getCurrentOrganizationId();
            if (organizationId) {
                return await handleApiRequestNotifications(
                    () =>
                        api.deleteTimeEntry(undefined, {
                            params: {
                                organization: organizationId,
                                timeEntry: timeEntryId,
                            },
                        }),
                    'Time entry deleted successfully',
                    'Failed to delete time entry'
                );
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
        },
    });

    const { mutateAsync: deleteTimeEntries } = useMutation({
        mutationFn: async (timeEntries: TimeEntry[]) => {
            const organizationId = getCurrentOrganizationId();
            const timeEntryIds = timeEntries.map((entry) => entry.id);
            if (organizationId) {
                return await handleApiRequestNotifications(
                    () =>
                        api.deleteTimeEntries(undefined, {
                            queries: {
                                ids: timeEntryIds,
                            },
                            params: {
                                organization: organizationId,
                            },
                        }),
                    'Time entries deleted successfully',
                    'Failed to delete time entries'
                );
            }
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['timeEntries'] });
        },
    });

    return {
        createTimeEntry,
        updateTimeEntry,
        updateTimeEntries,
        deleteTimeEntry,
        deleteTimeEntries,
    };
}
