<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 dark:border-primary-500/30 dark:bg-primary-500/10">
            <div class="flex items-start gap-3">
                <x-heroicon-o-information-circle class="mt-0.5 h-5 w-5 shrink-0 text-primary-600 dark:text-primary-400" />
                <div>
                    <p class="text-sm font-semibold text-primary-900 dark:text-primary-100">Review the submitted times, then choose the final slot.</p>
                    <p class="mt-1 text-sm text-primary-700 dark:text-primary-300">Pending requests do not hold capacity until you accept them.</p>
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(20rem,0.8fr)_minmax(0,1.5fr)]">
            <div class="space-y-6">
                <x-filament::section heading="Patient request">
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Request</dt>
                            <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $record->request_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">Patient</dt>
                            <dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $record->patient?->full_name ?? 'Unlinked' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500 dark:text-gray-400">Reason for visit</dt>
                            <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $record->encrypted_reason_for_visit ?: 'Not provided' }}</dd>
                        </div>
                    </dl>
                </x-filament::section>

                <x-filament::section heading="Submitted preferences">
                    <div class="space-y-3">
                        @foreach ($this->preferenceDecisions() as $index => $decision)
                            <button
                                type="button"
                                wire:click="selectPreference({{ $index }})"
                                aria-pressed="{{ $this->isSelectedPreference($decision['starts_at']->toIso8601String()) ? 'true' : 'false' }}"
                                class="flex w-full items-start gap-3 rounded-xl border p-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 {{ $this->isSelectedPreference($decision['starts_at']->toIso8601String()) ? 'border-primary-500 bg-primary-50 dark:border-primary-400 dark:bg-primary-500/10' : 'border-gray-200 hover:border-primary-300 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5' }}"
                            >
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full {{ $decision['available'] ? 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-400' : 'bg-danger-100 text-danger-700 dark:bg-danger-500/15 dark:text-danger-400' }}">
                                    @if ($decision['available'])
                                        <x-heroicon-s-check class="h-3.5 w-3.5" />
                                    @else
                                        <x-heroicon-s-x-mark class="h-3.5 w-3.5" />
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $decision['preference'] }}</span>
                                        <span class="text-xs font-semibold {{ $decision['available'] ? 'text-success-700 dark:text-success-400' : 'text-danger-700 dark:text-danger-400' }}">{{ $this->reasonLabel($decision['reason']) }}</span>
                                    </span>
                                    <span class="mt-1 block text-sm text-gray-600 dark:text-gray-300">{{ $decision['starts_at']->format('D, M j · g:i A') }}</span>
                                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Click to use this time</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </x-filament::section>

                <x-filament::section heading="Final appointment details">
                    <div class="space-y-4">
                        <div class="rounded-lg border border-primary-200 bg-primary-50 px-3 py-3 dark:border-primary-500/30 dark:bg-primary-500/10" aria-live="polite">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-primary-900 dark:text-primary-100">Selected slot</p>
                                    <p class="mt-1 text-sm text-primary-800 dark:text-primary-200">{{ $this->selectedSlotSummary() }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-primary-100 px-2.5 py-1 text-xs font-semibold text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">From {{ $this->selectedSlotSourceLabel() }}</span>
                            </div>
                        </div>

                        <div>
                            <label for="appointment-type" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium leading-6 text-gray-950 dark:text-white">Appointment type</label>
                            <select id="appointment-type" wire:model.live="appointmentTypeId" class="fi-select-input mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
                                <option value="">Select a type</option>
                                @foreach ($this->appointmentTypes() as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('appointmentTypeId') <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="duration" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium leading-6 text-gray-950 dark:text-white">Duration (minutes)</label>
                                <input id="duration" type="number" min="5" max="240" step="5" wire:model.live="durationMinutes" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
                                @error('durationMinutes') <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="optometrist" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium leading-6 text-gray-950 dark:text-white">Optometrist <span class="text-danger-600">*</span></label>
                                <select id="optometrist" wire:model.live="optometristId" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
                                    <option value="">Select a provider</option>
                                    @foreach ($this->optometrists() as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('optometristId') <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="scheduled-date" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium leading-6 text-gray-950 dark:text-white">Date</label>
                                <input id="scheduled-date" type="date" wire:model.live="scheduledDate" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
                                @error('scheduledDate') <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="scheduled-time" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium leading-6 text-gray-950 dark:text-white">Time</label>
                                <input id="scheduled-time" type="time" step="900" wire:model.live="scheduledTime" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
                                @error('scheduledTime') <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label for="referring-source" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium leading-6 text-gray-950 dark:text-white">Referring source</label>
                            <input id="referring-source" type="text" wire:model.live="referringSource" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
                            @error('referringSource') <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="contact-note" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3 text-sm font-medium leading-6 text-gray-950 dark:text-white">Contact note</label>
                            <textarea id="contact-note" rows="3" wire:model.live="contactNote" placeholder="Required when choosing a time outside the submitted preferences" class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"></textarea>
                            @error('contactNote') <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex items-center justify-end border-t border-gray-200 pt-4 dark:border-white/10">
                            <x-filament::button type="button" color="success" wire:click="accept" wire:loading.attr="disabled" wire:target="accept">
                                <span wire:loading.remove wire:target="accept">Accept &amp; Schedule</span>
                                <span wire:loading wire:target="accept">Checking slot…</span>
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            <x-filament::section heading="Schedule context" description="Existing appointments are shown as occupied. Click an open time to use it.">
                @livewire(\App\Filament\Resources\AppointmentRequests\Widgets\AppointmentRequestScheduleCalendar::class, [
                    'requestId' => $record->id,
                    'durationMinutes' => $durationMinutes,
                    'proposedStart' => $this->selectedDateTime(),
                ], key('request-schedule-calendar-'.$record->id.'-'.$scheduledDate.'-'.$scheduledTime.'-'.$durationMinutes))
                <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-gray-600 dark:text-gray-300" aria-label="Calendar legend">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-primary-500"></span>Scheduled</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-warning-500"></span>Checked in</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-violet-500"></span>Proposed slot</span>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
