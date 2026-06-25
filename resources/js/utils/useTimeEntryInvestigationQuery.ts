import axios from 'axios';
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed } from 'vue';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { fetchAllPages } from '@/utils/fetchAllPages';

export type TimeEntryInvestigation = {
    id: string;
    start: string;
    end: string | null;
    duration: number;
    description: string | null;
    project_id: string | null;
    member_id: string;
    user_id: string;
    member_name: string;
    member_email: string;
    is_running: boolean;
    investigation_flagged_at: string;
    investigation_reason: string | null;
    investigation_reviewed_at: string | null;
};

type InvestigationListResponse = {
    data: TimeEntryInvestigation[];
    meta: { per_page: number; last_page: number };
};

async function fetchInvestigationsPage(
    organizationId: string,
    page: number
): Promise<InvestigationListResponse> {
    const response = await axios.get<InvestigationListResponse>(
        `/api/v1/organizations/${organizationId}/time-entry-investigations`,
        { params: { page } }
    );

    return response.data;
}

export async function fetchAllInvestigations(
    organizationId: string
): Promise<TimeEntryInvestigation[]> {
    return fetchAllPages((page) => fetchInvestigationsPage(organizationId, page));
}

export function useTimeEntryInvestigationQuery() {
    const query = useQuery({
        queryKey: computed(() => ['time-entry-investigations', getCurrentOrganizationId()]),
        queryFn: async () => {
            const organizationId = getCurrentOrganizationId();
            if (!organizationId) {
                throw new Error('No organization');
            }

            const data = await fetchAllInvestigations(organizationId);
            return { data };
        },
        enabled: () => !!getCurrentOrganizationId(),
        staleTime: 1000 * 30,
    });

    const investigations = computed(() => query.data.value?.data ?? []);
    const pendingInvestigations = computed(() =>
        investigations.value.filter((entry) => entry.investigation_reviewed_at === null)
    );

    return {
        ...query,
        investigations,
        pendingInvestigations,
    };
}

export function useTimeEntryInvestigationMutations() {
    const queryClient = useQueryClient();

    const updateReason = useMutation({
        mutationFn: async ({
            timeEntryId,
            investigationReason,
        }: {
            timeEntryId: string;
            investigationReason: string;
        }) => {
            const organizationId = getCurrentOrganizationId();
            if (!organizationId) {
                throw new Error('No organization');
            }

            const response = await axios.patch<{ data: TimeEntryInvestigation }>(
                `/api/v1/organizations/${organizationId}/time-entries/${timeEntryId}/investigation`,
                {
                    investigation_reason: investigationReason,
                    mark_reviewed: true,
                }
            );

            return response.data.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['time-entry-investigations'] });
        },
    });

    return {
        updateReason,
    };
}
