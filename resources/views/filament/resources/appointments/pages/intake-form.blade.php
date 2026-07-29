@php
    use App\Enums\IntakeStatus;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $intake = $this->getIntake();
    $intakeStatus = $this->getIntakeStatus();
    $isReadOnly = $this->isReadOnly();
    $canReview = auth()->user()?->hasOptometristCapability() ?? false;
@endphp

<x-filament-panels::page>
    <div class="mx-auto w-full max-w-7xl space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a
                href="{{ $this->getBackUrl() }}"
                class="inline-flex w-fit items-center gap-2 text-sm font-medium text-primary-600 transition hover:text-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:text-primary-400 dark:hover:text-primary-300"
            >
                <x-filament::icon icon="heroicon-m-arrow-left" class="h-4 w-4" />
                Back to appointment
            </a>

            <div class="flex items-center gap-3">
                <x-filament::badge :color="$this->getStatusBadgeColor()">
                    {{ $this->getStatusLabel() }}
                </x-filament::badge>

                @if($intake)
                    <x-filament::button
                        tag="a"
                        href="{{ route('appointments.health-record.print', $this->appointment) }}"
                        target="_blank"
                        rel="noopener"
                        color="gray"
                        icon="heroicon-o-printer"
                        outlined
                    >
                        Print record
                    </x-filament::button>
                @endif
            </div>
        </div>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            Appointment {{ $this->appointment?->appointment_number ?? '—' }}
            <span aria-hidden="true">·</span>
            {{ $this->appointment?->appointmentType?->name ?? '—' }}
            <span aria-hidden="true">·</span>
            {{ $this->appointment?->scheduled_at?->format('M d, Y g:i A') ?? '—' }}
        </p>

        <x-filament::section>
            <x-slot name="heading">
                Patient Information
            </x-slot>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-health-record.field
                    field="full_name"
                    label="Full name"
                    :value="$this->formData['full_name'] ?? null"
                    :read-only="$isReadOnly"
                    required
                    class="sm:col-span-2"
                />
                <x-health-record.field
                    field="date_of_birth"
                    label="Date of birth"
                    type="date"
                    :value="$this->formData['date_of_birth'] ?? null"
                    :display-value="filled($this->formData['date_of_birth'] ?? null) ? Carbon::parse($this->formData['date_of_birth'])->format('M d, Y') : null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="gender"
                    label="Gender"
                    type="select"
                    :options="['male' => 'Male', 'female' => 'Female', 'other' => 'Other']"
                    :value="$this->formData['gender'] ?? null"
                    :display-value="filled($this->formData['gender'] ?? null) ? Str::headline($this->formData['gender']) : null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="occupation"
                    label="Occupation"
                    :value="$this->formData['occupation'] ?? null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="phone"
                    label="Phone number"
                    type="tel"
                    :value="$this->formData['phone'] ?? null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="email"
                    label="Email address"
                    type="email"
                    :value="$this->formData['email'] ?? null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="address"
                    label="Home address"
                    :value="$this->formData['address'] ?? null"
                    :read-only="$isReadOnly"
                    class="sm:col-span-2"
                />
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Complaints and Medical History
            </x-slot>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <x-health-record.field
                    field="chief_complaint"
                    label="Chief complaint"
                    type="textarea"
                    :rows="4"
                    placeholder="Example: Blurred distance vision in the left eye for two weeks"
                    :value="$this->formData['chief_complaint'] ?? null"
                    :read-only="$isReadOnly"
                    class="lg:col-span-2"
                />
                <x-health-record.field
                    field="past_ocular_history"
                    label="Past ocular history"
                    type="textarea"
                    :rows="4"
                    placeholder="Previous eye conditions, treatments, or procedures"
                    :value="$this->formData['past_ocular_history'] ?? null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="past_surgical_history"
                    label="Past surgical history"
                    type="textarea"
                    :rows="4"
                    placeholder="Previous operations and approximate dates"
                    :value="$this->formData['past_surgical_history'] ?? null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="past_medical_history"
                    label="Past medical history"
                    type="textarea"
                    :rows="4"
                    placeholder="Diabetes, hypertension, or other relevant conditions"
                    :value="$this->formData['past_medical_history'] ?? null"
                    :read-only="$isReadOnly"
                    class="lg:col-span-2"
                />
                <x-health-record.field
                    field="allergies"
                    label="Allergies"
                    type="textarea"
                    :rows="4"
                    placeholder="List known allergies and reactions, or leave blank if unknown"
                    :value="$this->formData['allergies'] ?? null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="medications"
                    label="Medications"
                    type="textarea"
                    :rows="4"
                    placeholder="List current medicines and doses when known"
                    :value="$this->formData['medications'] ?? null"
                    :read-only="$isReadOnly"
                />
            </div>
        </x-filament::section>

        @if(! $isReadOnly || ($intakeStatus === IntakeStatus::Submitted && $canReview))
            <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-6 dark:border-white/10 sm:flex-row sm:flex-wrap sm:justify-end">
                @if(! $isReadOnly)
                    <x-filament::button
                        wire:click="saveDraft"
                        wire:target="saveDraft,submit,saveAndVerify"
                        color="gray"
                        size="lg"
                    >
                        Save for later
                    </x-filament::button>

                    <x-filament::button
                        wire:click="submit"
                        wire:target="saveDraft,submit,saveAndVerify"
                        color="primary"
                        size="lg"
                    >
                        Submit for review
                    </x-filament::button>

                    @if($canReview)
                        <x-filament::button
                            wire:click="saveAndVerify"
                            wire:target="saveDraft,submit,saveAndVerify"
                            color="success"
                            size="lg"
                            icon="heroicon-o-check-circle"
                        >
                            Save and verify
                        </x-filament::button>
                    @endif
                @endif

                @if($intakeStatus === IntakeStatus::Submitted && $canReview)
                    <x-filament::button
                        wire:click="returnForCorrection"
                        wire:target="returnForCorrection,verify"
                        color="warning"
                        size="lg"
                        icon="heroicon-o-arrow-uturn-left"
                    >
                        Return for correction
                    </x-filament::button>

                    <x-filament::button
                        wire:click="verify"
                        wire:target="returnForCorrection,verify"
                        color="success"
                        size="lg"
                        icon="heroicon-o-check-circle"
                    >
                        Verify record
                    </x-filament::button>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
