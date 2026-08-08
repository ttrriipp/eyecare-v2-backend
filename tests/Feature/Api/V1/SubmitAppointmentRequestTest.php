<?php

use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\PatientAccountContact;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Create a user with the patient role but without a linked Patient record.
 * Simulates an unlinked patient account for appointment request testing.
 */
function unlinkedPatientUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->roles()->sync(
        Role::query()->where('name', Role::Patient)->pluck('id'),
    );

    return $user;
}

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

test('unlinked account can read appointment request availability', function () {
    $user = unlinkedPatientUser();

    $response = $this->actingAs($user)
        ->getJson('/api/v1/appointment-request-availability?date=2026-07-13');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'date',
                'timezone',
                'interval_minutes',
                'slot_duration_minutes',
                'day_status',
                'generated_at',
                'slots' => [
                    '*' => ['starts_at', 'ends_at', 'available', 'reason'],
                ],
            ],
        ])
        ->assertJsonPath('data.date', '2026-07-13')
        ->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.interval_minutes', 30)
        ->assertJsonPath('data.slot_duration_minutes', 30)
        ->assertJsonPath('data.day_status', 'open')
        ->assertJsonPath('data.slots.0.starts_at', '2026-07-13T09:00:00+08:00')
        ->assertJsonPath('data.slots.0.ends_at', '2026-07-13T09:30:00+08:00')
        ->assertJsonPath('data.slots.0.available', true)
        ->assertJsonPath('data.slots.0.reason', null);

    expect($response->json('data.slots'))->toHaveCount(16);
});

test('appointment request availability requires a current or future date', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/appointment-request-availability')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);

    $this->actingAs($user)
        ->getJson('/api/v1/appointment-request-availability?date=2026-07-09')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);

    $this->actingAs($user)
        ->getJson('/api/v1/appointment-request-availability?date=2026-07-13T00:00:00+08:00')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date']);
});

test('appointment request availability includes active request holds', function () {
    $user = User::factory()->create();

    AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'scheduled_at' => '2026-07-13 10:00:00',
        'provisional_duration_minutes' => 30,
        'status' => 'pending',
        'expires_at' => now()->addHours(24),
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointment-request-availability?date=2026-07-13')
        ->assertOk()
        ->assertJsonPath('data.slots.2.available', false)
        ->assertJsonPath('data.slots.2.reason', 'capacity_reached');
});

test('appointment request availability requires authentication', function () {
    $this->getJson('/api/v1/appointment-request-availability?date=2026-07-13')
        ->assertUnauthorized();
});

test('linked account can submit an appointment request', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Blurred vision in left eye',
        ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'request_number', 'status', 'scheduled_at', 'reason_for_visit']]);
});

test('unlinked account can submit an appointment request', function () {
    $user = unlinkedPatientUser([
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
    ]);

    // Create a verified contact for the account
    PatientAccountContact::factory()->create([
        'user_id' => $user->id,
        'type' => 'phone',
        'encrypted_value' => '09171234567',
        'lookup_hash' => hash('sha256', '09171234567'),
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
        ]);

    $response->assertCreated();

    // patient_id should be null for unlinked accounts
    $response->assertJsonPath('data.patient_id', null);
});

test('unlinked account snapshots its expanded appointment identity', function () {
    $user = unlinkedPatientUser([
        'email' => null,
    ]);

    PatientAccountContact::factory()
        ->phone('+639171234567')
        ->primary()
        ->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
            'identity' => [
                'phone' => '09171234567',
                'email' => 'ANA@EXAMPLE.COM',
                'first_name' => ' Ana ',
                'middle_name' => 'Santos',
                'last_name' => 'Reyes',
                'date_of_birth' => '1990-05-15',
                'gender' => 'female',
                'occupation' => 'Teacher',
                'address' => '123 Main St, Manila',
            ],
        ]);

    $response->assertCreated()
        ->assertJsonMissingPath('data.identity')
        ->assertJsonMissingPath('data.encrypted_identity_snapshot');

    $request = AppointmentRequest::query()->where('user_id', $user->id)->firstOrFail();

    expect($request->encrypted_identity_snapshot)->toMatchArray([
        'phone' => '+639171234567',
        'email' => 'ana@example.com',
        'first_name' => 'Ana',
        'middle_name' => 'Santos',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'gender' => 'female',
        'occupation' => 'Teacher',
        'address' => '123 Main St, Manila',
        'verified_contact_type' => 'phone',
    ]);

    expect($request->getRawOriginal('encrypted_identity_snapshot'))
        ->not->toContain('ana@example.com')
        ->not->toContain('123 Main St, Manila');
});

test('unlinked account can submit an appointment identity without an email', function () {
    $user = unlinkedPatientUser([
        'email' => null,
    ]);

    PatientAccountContact::factory()
        ->phone('+639171234567')
        ->primary()
        ->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
            'identity' => [
                'phone' => '+639171234567',
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'date_of_birth' => '1990-05-15',
                'gender' => 'female',
                'occupation' => 'Teacher',
                'address' => '123 Main St, Manila',
            ],
        ])
        ->assertCreated();

    $request = AppointmentRequest::query()->where('user_id', $user->id)->firstOrFail();

    expect($request->encrypted_identity_snapshot['email'])->toBeNull();
});

test('expanded appointment identity requires all non-email fields', function () {
    $user = unlinkedPatientUser();

    PatientAccountContact::factory()
        ->phone('+639171234567')
        ->primary()
        ->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
            'identity' => [
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'date_of_birth' => '1990-05-15',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'identity.phone',
            'identity.gender',
            'identity.occupation',
            'identity.address',
        ]);

    expect(AppointmentRequest::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('appointment identity rejects unknown patient claims', function () {
    $user = unlinkedPatientUser();

    PatientAccountContact::factory()
        ->phone('+639171234567')
        ->primary()
        ->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
            'identity' => [
                'phone' => '+639171234567',
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'date_of_birth' => '1990-05-15',
                'gender' => 'female',
                'occupation' => 'Teacher',
                'address' => '123 Main St, Manila',
                'patient_id' => 123,
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identity']);

    expect(AppointmentRequest::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('unlinked appointment identity phone must match the verified account phone', function () {
    $user = unlinkedPatientUser();

    PatientAccountContact::factory()
        ->phone('+639171234567')
        ->primary()
        ->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
            'identity' => [
                'phone' => '09170000000',
                'first_name' => 'Ana',
                'last_name' => 'Reyes',
                'date_of_birth' => '1990-05-15',
                'gender' => 'female',
                'occupation' => 'Teacher',
                'address' => '123 Main St, Manila',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identity.phone']);

    expect(AppointmentRequest::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('request requires reason for visit', function () {
    $user = unlinkedPatientUser();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['reason_for_visit']);
});

test('request rejects unavailable slot', function () {
    $user = unlinkedPatientUser();
    $optometrist = User::factory()->optometrist()->create();

    // Create an existing appointment at 10:00
    Appointment::factory()->create([
        'optometrist_id' => $optometrist->id,
        'duration_minutes' => 30,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);
});

test('linked request copies patient_id', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => '2026-07-13T10:00:00+08:00',
            'reason_for_visit' => 'Eye exam',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.patient_id', $user->patient->id);
});
