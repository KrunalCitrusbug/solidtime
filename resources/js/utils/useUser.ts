import { usePage } from '@inertiajs/vue3';
import type { User } from '@/types/models';

const page = usePage<{
    auth: {
        user: User;
    };
}>();
function getCurrentUserId() {
    return page.props.auth.user.id;
}

function getCurrentUser() {
    return page.props.auth.user;
}

function getCurrentOrganizationId() {
    return page.props.auth.user.current_team_id;
}

function getCurrentMembershipId() {
    return page.props.auth.user.all_teams.find((team) => team.id === getCurrentOrganizationId())
        ?.membership.id;
}

function getCurrentRole() {
    return page.props.auth.user.all_teams.find((team) => team.id === getCurrentOrganizationId())
        ?.membership.role;
}

function isEmployee() {
    return getCurrentRole() === 'employee';
}

function isTeamLead() {
    return getCurrentRole() === 'team_lead';
}

function isViewOnlyRole() {
    const role = getCurrentRole();
    return role === 'manager' || role === 'team_lead';
}

export {
    getCurrentOrganizationId,
    getCurrentUserId,
    getCurrentMembershipId,
    getCurrentRole,
    getCurrentUser,
    isEmployee,
    isTeamLead,
    isViewOnlyRole,
};
