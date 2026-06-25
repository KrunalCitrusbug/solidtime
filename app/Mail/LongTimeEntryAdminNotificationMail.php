<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TimeEntry;
use App\Service\LongTimeEntryService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class LongTimeEntryAdminNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TimeEntry $timeEntry,
    ) {}

    public function build(): self
    {
        $this->timeEntry->loadMissing(['user', 'member.user', 'project']);

        $durationHours = round(
            app(LongTimeEntryService::class)->getDurationInSeconds($this->timeEntry) / 3600,
            1
        );

        return $this->markdown('emails.long-time-entry-admin-notification', [
            'timeEntry' => $this->timeEntry,
            'durationHours' => $durationHours,
            'isRunning' => $this->timeEntry->end === null,
            'investigationUrl' => URL::route('time-log-investigation'),
        ])
            ->subject(__('Long time entry requires investigation'));
    }
}
