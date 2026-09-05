<?php

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\VisitRatings\Pages\ViewVisitRating;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Models\VisitRating;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('staff can navigate from feedback to its related visit records', function (): void {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create([
        'first_name' => 'Liza',
        'last_name' => 'Mendoza',
    ]);
    $appointment = Appointment::factory()->fulfilled()->create([
        'patient_id' => $patient->id,
        'reason_for_visit' => 'Follow-up examination',
    ]);
    $encounter = Encounter::factory()->completed()->create([
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
    ]);
    $rating = VisitRating::factory()->create([
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'encounter_id' => $encounter->id,
        'rating' => 4,
        'comment' => 'The visit was clear and helpful.',
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewVisitRating::class, ['record' => $rating->getRouteKey()])
        ->assertSuccessful()
        ->assertSee("Feedback for {$appointment->appointment_number}")
        ->assertSee('Visit context')
        ->assertSee('Patient feedback')
        ->assertSee($patient->full_name)
        ->assertSee($appointment->appointment_number)
        ->assertSee($appointment->appointmentType->name)
        ->assertSee('Follow-up examination')
        ->assertSee('Fulfilled')
        ->assertSee($encounter->encounter_number)
        ->assertSee('4 of 5 stars')
        ->assertSee('The visit was clear and helpful.')
        ->assertSee('href="'.PatientResource::getUrl('edit', ['record' => $patient]).'"', false)
        ->assertSee('href="'.AppointmentResource::getUrl('edit', ['record' => $appointment]).'"', false)
        ->assertSee('href="'.EncounterResource::getUrl('edit', ['record' => $encounter]).'"', false);
});

test('feedback detail explains when no consultation is linked', function (): void {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $appointment = Appointment::factory()->fulfilled()->create([
        'patient_id' => $patient->id,
    ]);
    $rating = VisitRating::factory()->create([
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'encounter_id' => null,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewVisitRating::class, ['record' => $rating->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('No consultation linked');
});
