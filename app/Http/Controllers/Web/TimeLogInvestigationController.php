<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TimeLogInvestigationController extends Controller
{
  /**
   * @throws AuthorizationException
   */
  public function index(): Response|RedirectResponse
  {
    $organization = $this->currentOrganization();
    $role = $this->member($organization)->role;

    if (! in_array($role, [Role::Owner->value, Role::Admin->value], true)) {
      return redirect()->route('time');
    }

    return Inertia::render('TimeLogInvestigation');
  }
}
