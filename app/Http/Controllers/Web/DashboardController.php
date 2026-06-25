<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Role;
use App\Service\DashboardService;
use App\Service\PermissionStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * @throws AuthorizationException
     */
    public function dashboard(DashboardService $dashboardService, PermissionStore $permissionStore): Response|RedirectResponse
    {
        $organization = $this->currentOrganization();
        $role = $this->member($organization)->role;

        if (! in_array($role, [Role::Owner->value, Role::Admin->value], true)) {
            return redirect()->route('time');
        }

        $user = $this->user();

        $latestTeamActivity = null;
        if ($permissionStore->has($organization, 'time-entries:view:all')) {
            $latestTeamActivity = $dashboardService->latestTeamActivity($organization);
        }

        $showBillableRate = $this->member($organization)->role !== Role::Employee->value || $organization->employees_can_see_billable_rates;

        return Inertia::render('Dashboard');
    }
}
