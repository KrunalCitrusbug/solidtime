<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\TimeEntryAggregationType;
use App\Http\Controllers\Api\V1\Controller;
use App\Http\Resources\V1\Report\DetailedWithDataReportResource;
use App\Models\Client;
use App\Models\Member;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Service\Dto\ReportPropertiesDto;
use App\Service\TimeEntryAggregationService;
use App\Service\TimeEntryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Get report by a share secret
     *
     * This endpoint is public and does not require authentication. The report must be public and not expired.
     * The report is considered expired if the `public_until` field is set and the date is in the past.
     * The report is considered public if the `is_public` field is set to `true`.
     *
     * @operationId getPublicReport
     */
    public function show(Request $request, TimeEntryAggregationService $timeEntryAggregationService): DetailedWithDataReportResource
    {
        $shareSecret = $request->header('X-Api-Key');
        if (! is_string($shareSecret)) {
            throw new ModelNotFoundException;
        }

        $report = Report::query()
            ->with([
                'organization',
            ])
            ->where('share_secret', '=', $shareSecret)
            ->where('is_public', '=', true)
            ->where(function (Builder $builder): void {
                /** @var Builder<Report> $builder */
                $builder->whereNull('public_until')
                    ->orWhere('public_until', '>', now());
            })
            ->firstOrFail();
        /** @var ReportPropertiesDto $properties */
        $properties = $report->properties;

        $timeEntriesQuery = TimeEntry::query()
            ->whereBelongsTo($report->organization, 'organization');

        $filter = new TimeEntryFilter($timeEntriesQuery);
        $filter->addStart($properties->start);
        $filter->addEnd($properties->end);
        $filter->addActive($properties->active);
        $filter->addBillable($properties->billable);
        $filter->addMemberIdsFilter($properties->memberIds?->toArray());
        $filter->addProjectIdsFilter($properties->projectIds?->toArray());
        $filter->addTagIdsFilter($properties->tagIds?->toArray());
        $filter->addTaskIdsFilter($properties->taskIds?->toArray());
        $filter->addClientIdsFilter($properties->clientIds?->toArray());
        $timeEntriesQuery = $filter->get();

        $data = $timeEntryAggregationService->getAggregatedTimeEntriesWithDescriptions(
            $timeEntriesQuery->clone(),
            $report->properties->group,
            $report->properties->subGroup,
            $report->properties->timezone,
            $report->properties->weekStart,
            false,
            $report->properties->start,
            $report->properties->end,
            true,
            $report->properties->roundingType,
            $report->properties->roundingMinutes,
        );
        $historyData = $timeEntryAggregationService->getAggregatedTimeEntriesWithDescriptions(
            $timeEntriesQuery->clone(),
            TimeEntryAggregationType::fromInterval($report->properties->historyGroup),
            null,
            $report->properties->timezone,
            $report->properties->weekStart,
            true,
            $report->properties->start,
            $report->properties->end,
            true,
            $report->properties->roundingType,
            $report->properties->roundingMinutes,
        );

        // For custom client-rendered layouts (weekly matrix, weekly-detailed
        // tree, attendance metrics) the shared view needs the raw entries plus
        // reference data to reproduce the layout in the browser.
        $extra = [];
        if (in_array($report->properties->format, ['weekly', 'weekly-detailed', 'attendance'], true)) {
            $extra = $this->buildRawEntriesPayload($timeEntriesQuery->clone());
        }

        return new DetailedWithDataReportResource($report, $data, $historyData, $extra);
    }

    /**
     * @param  Builder<TimeEntry>  $timeEntriesQuery
     * @return array<string, mixed>
     */
    private function buildRawEntriesPayload(Builder $timeEntriesQuery): array
    {
        $entries = $timeEntriesQuery
            ->select(['id', 'start', 'end', 'description', 'project_id', 'task_id', 'user_id'])
            ->orderBy('start')
            ->limit(50000)
            ->get();

        $entryData = $entries->map(fn (TimeEntry $e): array => [
            'start' => $e->start->toIso8601ZuluString(),
            'end' => $e->end?->toIso8601ZuluString(),
            'description' => $e->description,
            'project_id' => $e->project_id,
            'task_id' => $e->task_id,
            'user_id' => $e->user_id,
        ])->all();

        $projectIds = $entries->pluck('project_id')->filter()->unique()->values();
        $taskIds = $entries->pluck('task_id')->filter()->unique()->values();
        $userIds = $entries->pluck('user_id')->filter()->unique()->values();

        $projects = Project::query()->whereIn('id', $projectIds)->get(['id', 'name', 'color', 'client_id']);
        $clientIds = $projects->pluck('client_id')->filter()->unique()->values();
        $clients = Client::query()->whereIn('id', $clientIds)->get(['id', 'name']);
        $tasks = Task::query()->whereIn('id', $taskIds)->get(['id', 'name']);
        $members = Member::query()->whereIn('user_id', $userIds)->with('user:id,name')->get();

        return [
            'entries' => $entryData,
            'references' => [
                'projects' => $projects->map(fn (Project $p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'color' => $p->color,
                    'client_id' => $p->client_id,
                ])->all(),
                'clients' => $clients->map(fn (Client $c): array => ['id' => $c->id, 'name' => $c->name])->all(),
                'tasks' => $tasks->map(fn (Task $t): array => ['id' => $t->id, 'name' => $t->name])->all(),
                'users' => $members->map(fn (Member $m): array => ['id' => $m->user_id, 'name' => $m->user->name])->all(),
            ],
        ];
    }
}
