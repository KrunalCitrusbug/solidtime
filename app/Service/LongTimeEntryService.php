<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\Role;
use App\Mail\LongTimeEntryAdminNotificationMail;
use App\Models\Member;
use App\Models\Organization;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class LongTimeEntryService
{
    public const int THRESHOLD_HOURS = 8;

    public const int THRESHOLD_SECONDS = self::THRESHOLD_HOURS * 3600;

    /**
     * @param  Builder<TimeEntry>  $builder
     */
    public function scopeCompletedLongEntries(Builder $builder): void
    {
        $builder
            ->whereNotNull('end')
            ->whereRaw('EXTRACT(EPOCH FROM ("end" - "start")) > ?', [self::THRESHOLD_SECONDS]);
    }

    /**
     * @param  Builder<TimeEntry>  $builder
     */
    public function scopeRunningLongEntries(Builder $builder): void
    {
        $builder
            ->whereNull('end')
            ->where('start', '<', now()->subHours(self::THRESHOLD_HOURS));
    }

    public function getDurationInSeconds(TimeEntry $timeEntry): int
    {
        if ($timeEntry->end !== null) {
            return (int) $timeEntry->start->diffInSeconds($timeEntry->end);
        }

        return (int) $timeEntry->start->diffInSeconds(now());
    }

    public function isLongEntry(TimeEntry $timeEntry): bool
    {
        return $this->getDurationInSeconds($timeEntry) > self::THRESHOLD_SECONDS;
    }

    /**
     * @return Collection<int, string>
     */
    public function adminNotificationEmails(Organization $organization): Collection
    {
        return Member::query()
            ->whereBelongsTo($organization, 'organization')
            ->whereIn('role', [Role::Owner->value, Role::Admin->value])
            ->with('user')
            ->get()
            ->map(static fn (Member $member): ?string => $member->user->email)
            ->filter()
            ->unique()
            ->values();
    }

    public function flagAndNotifyAdmins(TimeEntry $timeEntry, bool $dryRun = false): void
    {
        if ($timeEntry->investigation_flagged_at !== null) {
            return;
        }

        if (! $dryRun) {
            $timeEntry->investigation_flagged_at = Carbon::now();
            $timeEntry->save();
        }

        if ($timeEntry->long_entry_admin_notified_at !== null) {
            return;
        }

        $timeEntry->loadMissing('organization');
        $emails = $this->adminNotificationEmails($timeEntry->organization);

        if ($emails->isEmpty()) {
            return;
        }

        if (! $dryRun) {
            foreach ($emails as $email) {
                Mail::to($email)->queue(new LongTimeEntryAdminNotificationMail($timeEntry));
            }

            $timeEntry->long_entry_admin_notified_at = Carbon::now();
            $timeEntry->save();
        }
    }
}
