import { usePage } from '@inertiajs/vue3';
import { getCurrentRole } from '@/utils/useUser';

const page = usePage<{
    auth: {
        permissions: string[];
    };
}>();

function currentUserHasPermission(permission: string) {
    if (Array.isArray(page.props.auth.permissions)) {
        return page.props.auth.permissions.includes(permission);
    }
    return false;
}

export function canUpdateOrganization() {
    return currentUserHasPermission('organizations:update');
}

export function canViewProjects() {
    return currentUserHasPermission('projects:view');
}

export function canCreateProjects() {
    return currentUserHasPermission('projects:create');
}

export function canUpdateProjects() {
    return currentUserHasPermission('projects:update');
}

export function canDeleteProjects() {
    return currentUserHasPermission('projects:delete');
}

export function canViewProjectMembers() {
    return currentUserHasPermission('project-members:view');
}

export function canCreateTasks() {
    return currentUserHasPermission('tasks:create');
}

export function canUpdateTasks() {
    return currentUserHasPermission('tasks:update');
}

export function canDeleteTasks() {
    return currentUserHasPermission('tasks:delete');
}

export function canCreateClients() {
    return currentUserHasPermission('clients:create');
}

export function canUpdateClients() {
    return currentUserHasPermission('clients:update');
}

export function canDeleteClients() {
    return currentUserHasPermission('clients:delete');
}

export function canViewClients() {
    return currentUserHasPermission('clients:view');
}

export function canViewMembers() {
    return currentUserHasPermission('members:view');
}

export function canViewMembersPage(): boolean {
    return canViewMembers() && !isTeamLeadRole();
}

export function canBrowseOrganizationDirectory(): boolean {
    return (
        currentUserHasPermission('projects:view:all') ||
        currentUserHasPermission('clients:view:all')
    );
}

export function canUpdateMembers() {
    return currentUserHasPermission('members:update');
}

export function canDeleteMembers() {
    return currentUserHasPermission('members:delete');
}

export function canMergeMembers() {
    return currentUserHasPermission('members:merge-into');
}

export function canMakeMembersPlaceholders() {
    return currentUserHasPermission('members:make-placeholder');
}

export function canInvitePlaceholderMembers() {
    return currentUserHasPermission('members:invite-placeholder');
}

export function canCreateInvitations() {
    return currentUserHasPermission('invitations:create');
}

export function canViewTags() {
    return currentUserHasPermission('tags:view');
}

export function canCreateTags() {
    return currentUserHasPermission('tags:create');
}

export function canUpdateTags() {
    return currentUserHasPermission('tags:update');
}

export function canDeleteTags() {
    return currentUserHasPermission('tags:delete');
}

export function canManageBilling() {
    return currentUserHasPermission('billing');
}

export function canViewReport() {
    return currentUserHasPermission('reports:view');
}
export function canUpdateReport() {
    return currentUserHasPermission('reports:update');
}
export function canDeleteReport() {
    return currentUserHasPermission('reports:delete');
}

export function canViewAllTimeEntries() {
    return currentUserHasPermission('time-entries:view:all');
}

/** Manager, Admin, Owner — org-wide time entry visibility and member filters. */
export function canViewOrganizationWideTimeEntries(): boolean {
    const role = getCurrentRole();
    return role === 'owner' || role === 'admin' || role === 'manager';
}

/** Team Lead — scoped to assigned projects (enforced on API). */
export function isTeamLeadRole(): boolean {
    return getCurrentRole() === 'team_lead';
}

/** Can view other people's entries (org-wide or project-scoped). */
export function canViewOthersTimeEntries(): boolean {
    return canViewOrganizationWideTimeEntries() || isTeamLeadRole();
}

/** Member filter on Time page — Manager+ only. */
export function canFilterByMember(): boolean {
    return canViewOrganizationWideTimeEntries();
}

/** Member filter on Reporting pages — Manager+ and Team Lead (scoped member list). */
export function canFilterReportsByMember(): boolean {
    return canViewOrganizationWideTimeEntries() || isTeamLeadRole();
}

export function canCreateTimeEntriesForOthers() {
    return currentUserHasPermission('time-entries:create:all');
}

export function canReassignTimeEntries() {
    return currentUserHasPermission('time-entries:update:all');
}

export function canUpdateAllTimeEntries() {
    return currentUserHasPermission('time-entries:update:all');
}

export function canViewDashboard(): boolean {
    const role = getCurrentRole();
    return role === 'owner' || role === 'admin';
}

export function canViewTimeLogInvestigation(): boolean {
    return canViewDashboard();
}

/** Manual logging (modal, timesheet cells, duration picker) — not the live start/stop timer. */
export function canCreateManualTimeEntries(): boolean {
    const role = getCurrentRole();
    if (role === 'employee' || role === 'manager' || role === 'team_lead') {
        return false;
    }
    return (
        currentUserHasPermission('time-entries:create:all') ||
        currentUserHasPermission('time-entries:update:all')
    );
}
export function canViewInvoices() {
    return currentUserHasPermission('invoices:view');
}
export function canCreateReports() {
    return currentUserHasPermission('reports:create');
}
