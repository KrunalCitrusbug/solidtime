<?php

declare(strict_types=1);

namespace App\Console\Commands\TimeEntry;

use App\Models\TimeEntry;
use App\Models\User;
use App\Service\LongTimeEntryService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TimeEntryFlagLongEntriesCommand extends Command
{
    protected $signature = 'time-entry:flag-long-entries '.
        '{ --dry-run : Do not save or send emails, only output what would happen }';

    protected $description = 'Flags time entries longer than 8 hours and notifies organization admins.';

    public function handle(LongTimeEntryService $longTimeEntryService): int
    {
        $this->comment('Flagging long time entries...');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Running in dry-run mode.');
        }

        $flagged = 0;

        TimeEntry::query()
            ->whereNull('investigation_flagged_at')
            ->where(function (Builder $query) use ($longTimeEntryService): void {
                $query->where(function (Builder $completedQuery) use ($longTimeEntryService): void {
                    $longTimeEntryService->scopeCompletedLongEntries($completedQuery);
                })->orWhere(function (Builder $runningQuery) use ($longTimeEntryService): void {
                    $longTimeEntryService->scopeRunningLongEntries($runningQuery);
                });
            })
            ->with(['organization', 'user'])
            ->whereHas('user', function (Builder $query): void {
                /** @var Builder<User> $query */
                $query->where('is_placeholder', '=', false);
            })
            ->orderBy('created_at', 'asc')
            ->chunk(500, function (Collection $timeEntries) use ($dryRun, $longTimeEntryService, &$flagged): void {
                /** @var Collection<int, TimeEntry> $timeEntries */
                foreach ($timeEntries as $timeEntry) {
                    $this->info('Flagging time entry '.$timeEntry->getKey().' for user "'.$timeEntry->user->email.'"');
                    $flagged++;
                    $longTimeEntryService->flagAndNotifyAdmins($timeEntry, $dryRun);
                }
            });

        $this->comment('Finished flagging '.$flagged.' long time entries.');

        return self::SUCCESS;
    }
}
