<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Requests\V1\TimeEntry\TimeEntryInvestigationUpdateRequest;
use App\Http\Resources\V1\TimeEntry\TimeEntryInvestigationCollection;
use App\Http\Resources\V1\TimeEntry\TimeEntryInvestigationResource;
use App\Models\Organization;
use App\Models\TimeEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class TimeEntryInvestigationController extends Controller
{
  /**
   * @throws AuthorizationException
   *
   * @operationId getTimeEntryInvestigations
   */
  public function index(Organization $organization): TimeEntryInvestigationCollection
  {
    $this->ensureAdmin($organization);

    $entries = TimeEntry::query()
      ->whereBelongsTo($organization, 'organization')
      ->whereNotNull('investigation_flagged_at')
      ->with(['user', 'member.user', 'project'])
      ->orderByDesc('investigation_flagged_at')
      ->paginate(config('app.pagination_per_page_default'));

    return TimeEntryInvestigationCollection::make($entries);
  }

  /**
   * @throws AuthorizationException
   *
   * @operationId updateTimeEntryInvestigation
   */
  public function update(
    Organization $organization,
    TimeEntry $timeEntry,
    TimeEntryInvestigationUpdateRequest $request,
  ): JsonResource {
    $this->ensureAdmin($organization);

    if ($timeEntry->organization_id !== $organization->id) {
      throw new AuthorizationException;
    }

    if ($timeEntry->investigation_flagged_at === null) {
      throw new AuthorizationException;
    }

    $timeEntry->investigation_reason = $request->validated('investigation_reason');

    if ($request->boolean('mark_reviewed', true)) {
      $timeEntry->investigation_reviewed_at = Carbon::now();
    }

    $timeEntry->save();
    $timeEntry->load(['user', 'member.user', 'project']);

    return TimeEntryInvestigationResource::make($timeEntry);
  }

  /**
   * @throws AuthorizationException
   */
  private function ensureAdmin(Organization $organization): void
  {
    $member = $this->member($organization);

    if (! in_array($member->role, [Role::Owner->value, Role::Admin->value], true)) {
      throw new AuthorizationException;
    }
  }
}
