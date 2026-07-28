<x-filament-panels::page>
    <div class="space-y-6" x-data="{ printUrl: '{{ route('appointments.health-record.print', $this->appointment) }}' }">
        {{-- Status Badge --}}
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a
                    href="{{ $this->getBackUrl() }}"
                    class="text-sm text-primary-600 hover:underline"
                >
                    &larr; Back to Appointment
                </a>
            </div>
            <x-filament::badge :color="$this->getStatusBadgeColor()">
                {{ $this->getStatusLabel() }}
            </x-filament::badge>
        </div>

        {{-- Appointment Summary --}}
        <x-filament::section>
            <x-slot name="heading">
                Appointment {{ $this->appointment?->appointment_number }}
            </x-slot>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">Patient</span>
                    <p class="font-medium">{{ $this->appointment?->patient?->full_name ?? '—' }}</p>
                </div>
                <div>
                    <span class="text-gray-500">Type</span>
                    <p class="font-medium">{{ $this->appointment?->appointmentType?->name ?? '—' }}</p>
                </div>
                <div>
                    <span class="text-gray-500">Scheduled</span>
                    <p class="font-medium">{{ $this->appointment?->scheduled_at?->format('M d, Y g:i A') ?? '—' }}</p>
                </div>
                <div>
                    <span class="text-gray-500">Status</span>
                    <p class="font-medium">{{ \Illuminate\Support\Str::headline($this->appointment?->status?->name ?? '—') }}</p>
                </div>
            </div>
        </x-filament::section>

        {{-- Demographics Snapshot --}}
        <x-filament::section>
            <x-slot name="heading">
                Patient Information
            </x-slot>
            <x-slot name="description">
                Demographic snapshot for this visit.
            </x-slot>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="fi-input-label text-sm font-medium text-gray-700">Full Name</label>
                    @if($this->isReadOnly())
                        <p class="mt-1 text-sm text-gray-900">{{ $this->formData['full_name'] ?? '—' }}</p>
                    @else
                        <input type="text" wire:model.live="formData.full_name" class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" />
                    @endif
                </div>

                <div>
                    <label class="fi-input-label text-sm font-medium text-gray-700">Date of Birth</label>
                    @if($this->isReadOnly())
                        <p class="mt-1 text-sm text-gray-900">{{ $this->formData['date_of_birth'] ? \Illuminate\Support\Carbon::parse($this->formData['date_of_birth'])->format('M d, Y') : '—' }}</p>
                    @else
                        <input type="date" wire:model.live="formData.date_of_birth" class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" />
                    @endif
                </div>

                <div>
                    <label class="fi-input-label text-sm font-medium text-gray-700">Gender</label>
                    @if($this->isReadOnly())
                        <p class="mt-1 text-sm text-gray-900">{{ $this->formData['gender'] ? \Illuminate\Support\Str::headline($this->formData['gender']) : '—' }}</p>
                    @else
                        <select wire:model.live="formData.gender" class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            <option value="">—</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    @endif
                </div>

                <div>
                    <label class="fi-input-label text-sm font-medium text-gray-700">Occupation</label>
                    @if($this->isReadOnly())
                        <p class="mt-1 text-sm text-gray-900">{{ $this->formData['occupation'] ?? '—' }}</p>
                    @else
                        <input type="text" wire:model.live="formData.occupation" class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" />
                    @endif
                </div>

                <div>
                    <label class="fi-input-label text-sm font-medium text-gray-700">Phone</label>
                    @if($this->isReadOnly())
                        <p class="mt-1 text-sm text-gray-900">{{ $this->formData['phone'] ?? '—' }}</p>
                    @else
                        <input type="text" wire:model.live="formData.phone" class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" />
                    @endif
                </div>

                <div>
                    <label class="fi-input-label text-sm font-medium text-gray-700">Email</label>
                    @if($this->isReadOnly())
                        <p class="mt-1 text-sm text-gray-900">{{ $this->formData['email'] ?? '—' }}</p>
                    @else
                        <input type="email" wire:model.live="formData.email" class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" />
                    @endif
                </div>

                <div class="md:col-span-2">
                    <label class="fi-input-label text-sm font-medium text-gray-700">Address</label>
                    @if($this->isReadOnly())
                        <p class="mt-1 text-sm text-gray-900">{{ $this->formData['address'] ?? '—' }}</p>
                    @else
                        <input type="text" wire:model.live="formData.address" class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm" />
                    @endif
                </div>
            </div>
        </x-filament::section>

        {{-- Clinical Information --}}
        <x-filament::section>
            <x-slot name="heading">
                Clinical Information
            </x-slot>
            <x-slot name="description">
                Chief complaint and medical history for this visit.
            </x-slot>

            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label class="fi-input-label text-sm font-medium text-gray-700">Chief Complaint</label>
                    @if($this->isReadOnly())
                        <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $this->formData['chief_complaint'] ?? '—' }}</p>
                    @else
                        <textarea
                            wire:model.live="formData.chief_complaint"
                            rows="3"
                            class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                            placeholder="Reason for visit..."
                        ></textarea>
                    @endif
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="fi-input-label text-sm font-medium text-gray-700">Past Ocular History</label>
                        @if($this->isReadOnly())
                            <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $this->formData['past_ocular_history'] ?? '—' }}</p>
                        @else
                            <textarea
                                wire:model.live="formData.past_ocular_history"
                                rows="3"
                                class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                placeholder="Previous eye conditions, surgeries..."
                            ></textarea>
                        @endif
                    </div>

                    <div>
                        <label class="fi-input-label text-sm font-medium text-gray-700">Past Surgical History</label>
                        @if($this->isReadOnly())
                            <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $this->formData['past_surgical_history'] ?? '—' }}</p>
                        @else
                            <textarea
                                wire:model.live="formData.past_surgical_history"
                                rows="3"
                                class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                placeholder="Previous surgeries..."
                            ></textarea>
                        @endif
                    </div>

                    <div>
                        <label class="fi-input-label text-sm font-medium text-gray-700">Past Medical History</label>
                        @if($this->isReadOnly())
                            <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $this->formData['past_medical_history'] ?? '—' }}</p>
                        @else
                            <textarea
                                wire:model.live="formData.past_medical_history"
                                rows="3"
                                class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                placeholder="Diabetes, hypertension, etc..."
                            ></textarea>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="fi-input-label text-sm font-medium text-gray-700">Allergies</label>
                        @if($this->isReadOnly())
                            <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $this->formData['allergies'] ?? 'None reported' }}</p>
                        @else
                            <textarea
                                wire:model.live="formData.allergies"
                                rows="2"
                                class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                placeholder="Drug allergies, reactions..."
                            ></textarea>
                        @endif
                    </div>

                    <div>
                        <label class="fi-input-label text-sm font-medium text-gray-700">Current Medications</label>
                        @if($this->isReadOnly())
                            <p class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $this->formData['medications'] ?? 'None reported' }}</p>
                        @else
                            <textarea
                                wire:model.live="formData.medications"
                                rows="2"
                                class="fi-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                placeholder="Current medications..."
                            ></textarea>
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Verification Info --}}
        @if($this->getIntakeStatus() === \App\Enums\IntakeStatus::Verified && $this->getIntake())
            <x-filament::section>
                <x-slot name="heading">
                    Verification
                </x-slot>

                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">Verified by</span>
                        <p class="font-medium">{{ $this->getIntake()->verifiedBy?->name ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-gray-500">Verified at</span>
                        <p class="font-medium">{{ $this->getIntake()->verified_at?->format('M d, Y g:i A') ?? '—' }}</p>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- Action Buttons --}}
        <div class="flex items-center gap-3 justify-between">
            {{-- Print (always available when record exists) --}}
            <div>
                @if($this->getIntake())
                    <a
                        href="{{ route('appointments.health-record.print', $this->appointment) }}"
                        target="_blank"
                        class="fi-btn fi-btn-size-md fi-btn-color-gray inline-flex items-center gap-1.5"
                    >
                        <svg class="fi-btn-icon h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5 2a2 2 0 00-2 2v1h10V4a2 2 0 00-2-2H5zm6 5V5H5v2h6zm2 1H3v6a2 2 0 002 2h10a2 2 0 002-2V8h-2v5H5V8h8z" clip-rule="evenodd" />
                        </svg>
                        Print Patient Health Record
                    </a>
                @endif
            </div>

            <div class="flex items-center gap-3">
                {{-- Draft / Editable actions --}}
                @if(!$this->isReadOnly())
                    <x-filament::button
                        wire:click="saveDraft"
                        color="gray"
                        size="lg"
                    >
                        Save for later
                    </x-filament::button>

                    <x-filament::button
                        wire:click="submit"
                        color="primary"
                        size="lg"
                    >
                        Submit for review
                    </x-filament::button>

                    {{-- Save and verify (optometrist only, on draft) --}}
                    @if(auth()->user()?->hasOptometristCapability())
                        <x-filament::button
                            wire:click="saveAndVerify"
                            color="success"
                            size="lg"
                            icon="heroicon-o-check-circle"
                        >
                            Save and verify
                        </x-filament::button>
                    @endif
                @endif

                {{-- Return for correction (submitted, authorized users) --}}
                @if($this->getIntakeStatus() === \App\Enums\IntakeStatus::Submitted && auth()->user()?->hasOptometristCapability())
                    <x-filament::button
                        wire:click="returnForCorrection"
                        color="warning"
                        size="lg"
                        icon="heroicon-o-arrow-uturn-left"
                    >
                        Return for correction
                    </x-filament::button>
                @endif

                {{-- Verify (submitted, authorized users) --}}
                @if($this->getIntakeStatus() === \App\Enums\IntakeStatus::Submitted && auth()->user()?->hasOptometristCapability())
                    <x-filament::button
                        wire:click="verify"
                        color="success"
                        size="lg"
                        icon="heroicon-o-check-circle"
                    >
                        Verify
                    </x-filament::button>
                @endif
            </div>
        </div>

        {{-- Submission Info --}}
        @if($this->getIntakeStatus() === \App\Enums\IntakeStatus::Submitted && $this->getIntake())
            <x-filament::section>
                <x-slot name="heading">
                    Submission
                </x-slot>

                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">Submitted by</span>
                        <p class="font-medium">{{ $this->getIntake()->submittedBy?->name ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-gray-500">Submitted at</span>
                        <p class="font-medium">{{ $this->getIntake()->submitted_at?->format('M d, Y g:i A') ?? '—' }}</p>
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
