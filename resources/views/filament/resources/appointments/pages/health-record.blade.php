<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Appointment Information --}}
        <x-filament::section>
            <x-slot name="heading">
                Appointment Information
            </x-slot>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-sm text-gray-500">Appointment #</div>
                    <div class="font-medium">{{ $appointmentData['appointment_number'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Type</div>
                    <div class="font-medium">{{ $appointmentData['appointment_type'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Visit Reason</div>
                    <div class="font-medium">{{ $appointmentData['visit_reason'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Scheduled</div>
                    <div class="font-medium">{{ $appointmentData['scheduled_at'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Status</div>
                    <div class="font-medium">{{ ucfirst($appointmentData['status']) }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Optometrist</div>
                    <div class="font-medium">{{ $appointmentData['optometrist'] }}</div>
                </div>
                @if($appointmentData['referring_source'] !== '—')
                    <div class="col-span-2">
                        <div class="text-sm text-gray-500">Referral Source</div>
                        <div class="font-medium">{{ $appointmentData['referring_source'] }}</div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Patient Demographics --}}
        <x-filament::section>
            <x-slot name="heading">
                Patient Demographics
            </x-slot>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-sm text-gray-500">Full Name</div>
                    <div class="font-medium">{{ $patientData['full_name'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Date of Birth</div>
                    <div class="font-medium">{{ $patientData['date_of_birth'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Gender</div>
                    <div class="font-medium">{{ ucfirst($patientData['gender'] ?? '—') }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Occupation</div>
                    <div class="font-medium">{{ $patientData['occupation'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Phone</div>
                    <div class="font-medium">{{ $patientData['phone'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Address</div>
                    <div class="font-medium">{{ $patientData['address'] }}</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Health History (from Intake) --}}
        <x-filament::section>
            <x-slot name="heading">
                Health History
            </x-slot>

            <div class="space-y-4">
                <div>
                    <div class="text-sm text-gray-500">Chief Complaint</div>
                    <div class="font-medium">{{ $intakeData['chief_complaint'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Past Ocular History</div>
                    <div class="font-medium">{{ $intakeData['past_ocular_history'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Past Surgical History</div>
                    <div class="font-medium">{{ $intakeData['past_surgical_history'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Past Medical History</div>
                    <div class="font-medium">{{ $intakeData['past_medical_history'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Allergies</div>
                    <div class="font-medium">{{ $intakeData['allergies'] }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Medications</div>
                    <div class="font-medium">{{ $intakeData['medications'] }}</div>
                </div>
            </div>
        </x-filament::section>

        {{-- Clinical Findings (optometrist-only) --}}
        @if(auth()->user()?->hasOptometristCapability())
            <x-filament::section>
                <x-slot name="heading">
                    Clinical Findings
                    <span class="text-sm text-gray-400 font-normal ml-2">(Optometrist Only)</span>
                </x-slot>

                @if(count($encounterData) > 0)
                    @foreach($encounterData as $encounter)
                        <div class="mb-4 p-4 bg-gray-50 rounded-lg">
                            <div class="text-sm text-gray-500 mb-2">Encounter #{{ $encounter['encounter_number'] }}</div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <div class="text-sm text-gray-500">Findings</div>
                                    <div class="font-medium">{{ $encounter['findings'] }}</div>
                                </div>
                                <div>
                                    <div class="text-sm text-gray-500">Remarks</div>
                                    <div class="font-medium">{{ $encounter['remarks'] }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div class="text-gray-500">No encounters recorded yet.</div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
