<?php

use App\Http\Resources\AppointmentTypeResource;
use App\Models\AppointmentType;
use App\Models\AppointmentTypeVisitReasonPreset;
use Illuminate\Database\Eloquent\Collection;

test('appointment type resource always returns an empty preset array when none are loaded', function () {
    $appointmentType = AppointmentType::make([
        'name' => 'Routine Check-up',
        'patient_label' => 'Regular eye examination',
        'patient_description' => null,
        'duration_minutes' => 30,
        'requires_referral' => false,
    ]);

    $payload = json_decode((new AppointmentTypeResource($appointmentType))->toJson(), true);

    expect($payload['visit_reason_presets'])->toBe([]);
});

test('appointment type resource serializes loaded presets with only patient-safe fields', function () {
    $appointmentType = AppointmentType::make([
        'name' => 'Problem/Urgent Visit',
        'patient_label' => 'Problem or urgent visit',
        'patient_description' => 'For a new or worsening eye concern.',
        'duration_minutes' => 30,
        'requires_referral' => false,
    ]);
    $preset = AppointmentTypeVisitReasonPreset::make([
        'label' => 'Blurred or reduced vision',
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $preset->setAttribute('id', 21);
    $appointmentType->setRelation('activeVisitReasonPresets', new Collection([$preset]));

    $payload = json_decode((new AppointmentTypeResource($appointmentType))->toJson(), true);

    expect($payload)
        ->toMatchArray([
            'id' => null,
            'name' => 'Problem or urgent visit',
            'description' => 'For a new or worsening eye concern.',
            'duration_minutes' => 30,
            'requires_referral' => false,
            'visit_reason_presets' => [
                ['id' => 21, 'label' => 'Blurred or reduced vision'],
            ],
        ])
        ->and($payload['visit_reason_presets'][0])->not->toHaveKeys(['sort_order', 'is_active']);
});
