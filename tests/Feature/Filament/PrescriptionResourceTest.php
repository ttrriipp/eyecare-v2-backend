<?php

use App\Filament\Resources\Prescriptions\Pages\CreatePrescription;
use App\Filament\Resources\Prescriptions\Pages\EditPrescription;
use App\Filament\Resources\Prescriptions\Pages\ListPrescriptions;
use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff and admin users can list prescriptions', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $prescriptions = Prescription::factory()->count(2)->create();

    $this->actingAs($user);

    Livewire::test(ListPrescriptions::class)
        ->assertCanSeeTableRecords($prescriptions);
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('staff can record prescriptions linked to customers with optional appointments', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(CreatePrescription::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'appointment_id' => $appointment->id,
            'od_sphere' => -2.25,
            'od_cylinder' => -0.50,
            'od_axis' => 90,
            'od_add' => 1.50,
            'os_sphere' => -2.00,
            'os_cylinder' => -0.75,
            'os_axis' => 85,
            'os_add' => 1.50,
            'pd' => 62.5,
            'prescribed_at' => '2026-06-01',
            'expires_at' => '2027-06-01',
            'notes' => 'Annual exam update.',
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $prescription = Prescription::query()
        ->where('customer_id', $customer->id)
        ->where('appointment_id', $appointment->id)
        ->first();

    expect($prescription)->not->toBeNull()
        ->and($prescription->created_by)->toBe($staff->id)
        ->and($prescription->od_sphere)->toBe('-2.25')
        ->and($prescription->pd)->toBe('62.5')
        ->and($prescription->notes)->toBe('Annual exam update.');
});

test('staff can edit prescriptions', function () {
    $staff = User::factory()->staff()->create();
    $prescription = Prescription::factory()->create([
        'notes' => 'Original notes.',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditPrescription::class, ['record' => $prescription->getRouteKey()])
        ->fillForm([
            'notes' => 'Updated notes.',
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($prescription->fresh()->notes)->toBe('Updated notes.');
});

test('prescription form validates od os pd and date fields', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();

    $this->actingAs($staff);

    Livewire::test(CreatePrescription::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'od_sphere' => 25,
            'od_axis' => 200,
            'os_sphere' => -25,
            'pd' => -1,
            'prescribed_at' => '2026-06-10',
            'expires_at' => '2026-06-01',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'od_sphere',
            'od_axis',
            'os_sphere',
            'pd',
            'expires_at',
        ])
        ->assertNotNotified();
});

test('staff can create a prescription for a walk-in customer (no email or password)', function () {
    $staff = User::factory()->staff()->create();
    $walkIn = User::factory()->walkIn()->create(['phone' => '09171234567']);

    $this->actingAs($staff);

    Livewire::test(CreatePrescription::class)
        ->fillForm([
            'customer_id' => $walkIn->id,
            'od_sphere' => -1.50,
            'os_sphere' => -1.25,
            'pd' => 62.0,
            'prescribed_at' => '2026-06-01',
            'expires_at' => '2027-06-01',
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Prescription::class, [
        'customer_id' => $walkIn->id,
        'created_by' => $staff->id,
    ]);

    expect($walkIn->email)->toBeNull()
        ->and($walkIn->password)->toBeNull();
});
