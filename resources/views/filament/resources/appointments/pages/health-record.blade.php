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

        {{-- Clinical Encounter --}}
        @if($encounterData)
            <x-filament::section>
                <x-slot name="heading">
                    Clinical Encounter
                </x-slot>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <div class="text-sm text-gray-500">Encounter #</div>
                        <div class="font-medium">{{ $encounterData['encounter_number'] }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Status</div>
                        <div class="font-medium">{{ ucfirst(str_replace('_', ' ', $encounterData['status'])) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Optometrist</div>
                        <div class="font-medium">{{ $encounterData['optometrist'] }}</div>
                    </div>
                </div>

                @if(auth()->user()?->hasOptometristCapability())
                    <div class="mt-4 space-y-4">
                        <div>
                            <div class="text-sm text-gray-500">Findings</div>
                            <div class="font-medium whitespace-pre-wrap">{{ $encounterData['findings'] }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">Remarks</div>
                            <div class="font-medium whitespace-pre-wrap">{{ $encounterData['remarks'] }}</div>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        @endif

        {{-- Prescription --}}
        @if($prescriptionData)
            <x-filament::section>
                <x-slot name="heading">
                    Prescription
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="text-left py-2 text-gray-500"></th>
                                <th class="text-center py-2 text-gray-500">Sphere</th>
                                <th class="text-center py-2 text-gray-500">Cylinder</th>
                                <th class="text-center py-2 text-gray-500">Axis</th>
                                <th class="text-center py-2 text-gray-500">Add</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b">
                                <td class="py-2 font-medium">OD (Right)</td>
                                <td class="py-2 text-center">{{ $prescriptionData['od_sphere'] }}</td>
                                <td class="py-2 text-center">{{ $prescriptionData['od_cylinder'] }}</td>
                                <td class="py-2 text-center">{{ $prescriptionData['od_axis'] }}</td>
                                <td class="py-2 text-center">{{ $prescriptionData['od_add'] }}</td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 font-medium">OS (Left)</td>
                                <td class="py-2 text-center">{{ $prescriptionData['os_sphere'] }}</td>
                                <td class="py-2 text-center">{{ $prescriptionData['os_cylinder'] }}</td>
                                <td class="py-2 text-center">{{ $prescriptionData['os_axis'] }}</td>
                                <td class="py-2 text-center">{{ $prescriptionData['os_add'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="grid grid-cols-3 gap-4 mt-4">
                    <div>
                        <div class="text-sm text-gray-500">PD</div>
                        <div class="font-medium">{{ $prescriptionData['pd'] }} mm</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Prescribed</div>
                        <div class="font-medium">{{ $prescriptionData['prescribed_at'] }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Expires</div>
                        <div class="font-medium">{{ $prescriptionData['expires_at'] }}</div>
                    </div>
                </div>

                @if($prescriptionData['notes'] !== '—')
                    <div class="mt-4">
                        <div class="text-sm text-gray-500">Notes</div>
                        <div class="font-medium whitespace-pre-wrap">{{ $prescriptionData['notes'] }}</div>
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
