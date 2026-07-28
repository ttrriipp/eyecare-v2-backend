@php
    use App\Enums\IntakeStatus;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $intake = $this->getIntake();
    $intakeStatus = $this->getIntakeStatus();
    $isReadOnly = $this->isReadOnly();
    $canReview = auth()->user()?->hasOptometristCapability() ?? false;

    $statusDescription = match ($intakeStatus) {
        null => 'Complete the patient information and clinical history, then save or submit the record.',
        IntakeStatus::Draft => 'This record is still editable. Submit it when the information is ready for review.',
        IntakeStatus::Submitted => 'This record is locked while it waits for an optometrist’s review.',
        IntakeStatus::Verified => 'This record has been reviewed and locked for this visit.',
    };
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

        <x-filament::section>
            <x-slot name="heading">
                Appointment overview
            </x-slot>
            <x-slot name="description">
                Visit context and record workflow.
            </x-slot>

            <div
                id="health-record-status-description"
                class="mb-6 flex flex-col gap-3 rounded-lg bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 sm:flex-row sm:items-center sm:justify-between"
                role="status"
            >
                <div>
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $this->getStatusLabel() }}
                    </p>
                    <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        {{ $statusDescription }}
                    </p>
                </div>

                <x-filament::badge :color="$this->getStatusBadgeColor()" size="lg">
                    {{ $this->getStatusLabel() }}
                </x-filament::badge>
            </div>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Appointment number</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $this->appointment?->appointment_number ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Appointment type</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $this->appointment?->appointmentType?->name ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Scheduled date and time</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $this->appointment?->scheduled_at?->format('M d, Y g:i A') ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Appointment status</dt>
                    <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                        {{ Str::headline($this->appointment?->status?->name ?? '—') }}
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Patient information
            </x-slot>
            <x-slot name="description">
                A snapshot of the patient’s information for this visit.
            </x-slot>

            <div class="grid grid-cols-1 gap-8 xl:grid-cols-3">
                <div class="space-y-4 xl:col-span-2">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Identity and demographics</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Confirm these details with the patient.</p>
                    </div>

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
                            class="sm:col-span-2"
                        />
                    </div>
                </div>

                <div class="space-y-4 border-t border-gray-200 pt-6 dark:border-white/10 xl:border-s xl:border-t-0 xl:ps-8 xl:pt-0">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Contact details</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Used only for clinic communication.</p>
                    </div>

                    <div class="grid grid-cols-1 gap-5">
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
                        />
                    </div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Reason for visit
            </x-slot>
            <x-slot name="description">
                Record the patient’s main concern in their own words when possible.
            </x-slot>

            <x-health-record.field
                field="chief_complaint"
                label="Chief complaint"
                type="textarea"
                :rows="4"
                placeholder="Example: Blurred distance vision in the left eye for two weeks"
                :value="$this->formData['chief_complaint'] ?? null"
                :read-only="$isReadOnly"
            />
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Medical history
            </x-slot>
            <x-slot name="description">
                Previous eye, surgical, and general medical history relevant to this visit.
            </x-slot>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
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
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Safety information
            </x-slot>
            <x-slot name="description">
                Review allergies and current medications before beginning the examination.
            </x-slot>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <x-health-record.field
                    field="allergies"
                    label="Allergies and reactions"
                    type="textarea"
                    :rows="4"
                    placeholder="List known allergies and reactions, or leave blank if unknown"
                    :value="$this->formData['allergies'] ?? null"
                    :read-only="$isReadOnly"
                />
                <x-health-record.field
                    field="medications"
                    label="Current medications"
                    type="textarea"
                    :rows="4"
                    placeholder="List current medicines and doses when known"
                    :value="$this->formData['medications'] ?? null"
                    :read-only="$isReadOnly"
                />
            </div>
        </x-filament::section>

        @if($intakeStatus === IntakeStatus::Submitted && $intake)
            <x-filament::section>
                <x-slot name="heading">
                    Submission details
                </x-slot>

                <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Submitted by</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $intake->submittedBy?->name ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Submitted at</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $intake->submitted_at?->format('M d, Y g:i A') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </x-filament::section>
        @elseif($intakeStatus === IntakeStatus::Verified && $intake)
            <x-filament::section>
                <x-slot name="heading">
                    Verification details
                </x-slot>

                <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Verified by</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $intake->verifiedBy?->name ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Verified at</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $intake->verified_at?->format('M d, Y g:i A') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </x-filament::section>
        @endif

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
