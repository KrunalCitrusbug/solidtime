<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\TimeEntry;

use App\Http\Resources\V1\BaseResource;
use App\Models\TimeEntry;
use App\Service\LongTimeEntryService;
use Illuminate\Http\Request;

/**
 * @property TimeEntry $resource
 */
class TimeEntryInvestigationResource extends BaseResource
{
  /**
   * @return array<string, string|bool|int|null>
   */
  public function toArray(Request $request): array
  {
    $durationSeconds = app(LongTimeEntryService::class)->getDurationInSeconds($this->resource);

    return [
      'id' => $this->resource->id,
      'start' => $this->formatDateTime($this->resource->start),
      'end' => $this->formatDateTime($this->resource->end),
      'duration' => $durationSeconds,
      'description' => $this->resource->description,
      'project_id' => $this->resource->project_id,
      'member_id' => $this->resource->member_id,
      'user_id' => $this->resource->user_id,
      'member_name' => $this->resource->member?->user?->name ?? $this->resource->user?->name,
      'member_email' => $this->resource->member?->user?->email ?? $this->resource->user?->email,
      'is_running' => $this->resource->end === null,
      'investigation_flagged_at' => $this->formatDateTime($this->resource->investigation_flagged_at),
      'investigation_reason' => $this->resource->investigation_reason,
      'investigation_reviewed_at' => $this->formatDateTime($this->resource->investigation_reviewed_at),
    ];
  }
}
