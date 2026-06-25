@component('mail::message')
{{ __('A time entry logged by :name exceeds :hours hours and needs investigation.', [
    'name' => $timeEntry->user->name,
    'hours' => \App\Service\LongTimeEntryService::THRESHOLD_HOURS,
]) }}

@if($isRunning)
{{ __('This entry is still running (currently about :duration hours).', ['duration' => $durationHours]) }}
@else
{{ __('Logged duration: about :duration hours.', ['duration' => $durationHours]) }}
@endif

@if(!empty($timeEntry->description))
**{{ __('Description') }}:** {{ $timeEntry->description }}
@endif

@if($timeEntry->project)
**{{ __('Project') }}:** {{ $timeEntry->project->name }}
@endif

{{ __('Please review the entry and record a reason on the investigation page.') }}

@component('mail::button', ['url' => $investigationUrl])
{{ __('Open investigation page') }}
@endcomponent

@endcomponent
