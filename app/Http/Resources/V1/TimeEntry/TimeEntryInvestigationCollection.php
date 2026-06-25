<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\TimeEntry;

use App\Http\Resources\PaginatedResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TimeEntryInvestigationCollection extends ResourceCollection implements PaginatedResourceCollection
{
  /**
   * @var string
   */
  public $collects = TimeEntryInvestigationResource::class;
}
