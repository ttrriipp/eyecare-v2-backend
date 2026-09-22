<div class="mb-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-400">
    <span class="font-semibold">Reschedule limit:</span>
    @if($reschedules->isEmpty())
        This appointment can only be rescheduled once.
    @else
        This appointment has already been rescheduled and cannot be rescheduled again.
    @endif
</div>

@if($reschedules->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">
        No reschedules recorded.
    </p>
@else
    <ol class="space-y-3" aria-label="Appointment reschedule history">
        @foreach($reschedules as $reschedule)
            @php
                $initiatedBy = match ($reschedule->initiated_by) {
                    'clinic' => 'Clinic',
                    'patient' => 'Patient',
                    default => \Illuminate\Support\Str::headline($reschedule->initiated_by ?? 'Unknown'),
                };
            @endphp

            <li class="rounded-lg border border-gray-200 bg-gray-50/60 p-3 dark:border-white/10 dark:bg-white/5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Previous slot</p>
                        <p class="mt-1 break-words text-sm font-medium text-gray-900 dark:text-white">
                            {{ $reschedule->previous_scheduled_at?->format('M j, Y g:i A') ?? '—' }}
                        </p>
                    </div>

                    <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">New slot</p>
                        <p class="mt-1 break-words text-sm font-medium text-gray-900 dark:text-white">
                            {{ $reschedule->new_scheduled_at?->format('M j, Y g:i A') ?? '—' }}
                        </p>
                    </div>
                </div>

                <div class="mt-3 border-t border-gray-200 pt-3 dark:border-white/10">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $initiatedBy }} initiated
                        @if($reschedule->rescheduled_at)
                            · {{ $reschedule->rescheduled_at->format('M j, Y g:i A') }}
                        @endif
                    </p>

                    @if($reschedule->actor?->full_name)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Changed by {{ $reschedule->actor->full_name }}
                        </p>
                    @endif

                    @if(filled($reschedule->reason_category))
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                            <span class="font-medium text-gray-900 dark:text-white">Reason:</span>
                            {{ \Illuminate\Support\Str::headline($reschedule->reason_category) }}
                        </p>
                    @endif

                    @if(filled($reschedule->reason_details))
                        <p class="mt-1 whitespace-pre-line break-words text-sm text-gray-700 dark:text-gray-300">
                            {{ $reschedule->reason_details }}
                        </p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
