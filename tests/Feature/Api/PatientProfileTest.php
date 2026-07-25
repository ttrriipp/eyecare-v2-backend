<?php

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('registration creates a user and a linked patient in one transaction', function () {
    $this->postJson('/api/register', [
        'name' => 'Jane Patient',
        'email' => 'jane@example.com',
        'phone' => '09171234567',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $user = User::query()->where('email', 'jane@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->role->name)->toBe('patient');

    $patient = Patient::query()->where('user_id', $user->id)->first();
    expect($patient)->not->toBeNull()
        ->and($patient->full_name)->toBe('Jane Patient')
        ->and($patient->patient_number)->toStartWith('PAT-')
        ->and($patient->account->id)->toBe($user->id);
});

test('failed patient creation rolls back account creation', function () {
    // Register an observer that forces Patient creation to fail,
    // proving the registration is truly transactional.
    Patient::observe(new class
    {
        public function creating(Model $model): void
        {
            throw new RuntimeException('Simulated patient creation failure');
        }
    });

    $this->postJson('/api/register', [
        'name' => 'Jane Patient',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertServerError();

    $this->assertDatabaseMissing(User::class, [
        'email' => 'jane@example.com',
    ]);

    $this->assertDatabaseCount('patients', 0);
});

test('registration response contains patient-safe profile contract', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Jane Patient',
        'email' => 'jane@example.com',
        'phone' => '09171234567',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'token',
                'user' => [
                    'id',
                    'patient_number',
                    'name',
                    'email',
                    'phone',
                    'role',
                ],
            ],
        ])
        ->assertJsonPath('data.user.role', 'patient')
        ->assertJsonPath('data.user.name', 'Jane Patient')
        ->assertJsonPath('data.user.email', 'jane@example.com')
        ->assertJsonPath('data.user.phone', '09171234567');

    // Ensure patient_number is present and formatted
    $patientNumber = $response->json('data.user.patient_number');
    expect($patientNumber)->not->toBeNull()
        ->and($patientNumber)->toStartWith('PAT-');
});
