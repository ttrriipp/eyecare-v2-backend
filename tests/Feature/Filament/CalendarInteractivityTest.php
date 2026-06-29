<?php

use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
});

test('conflictsWith detects an appointment within 30 minutes', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => '2026-07-01 10:00:00',
    ]);

    expect(Appointment::conflictsWith(Carbon::parse('2026-07-01 10:20:00')))->toBeTrue()
        ->and(Appointment::conflictsWith(Carbon::parse('2026-07-01 11:00:00')))->toBeFalse();
});

test('conflictsWith ignores the excluded appointment id', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => '2026-07-01 10:00:00',
    ]);

    expect(Appointment::conflictsWith(Carbon::parse('2026-07-01 10:00:00'), $appointment->id))->toBeFalse();
});

test('conflictsWith ignores cancelled appointments', function () {
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->value('id');
    Appointment::factory()->create([
        'appointment_status_id' => $cancelled,
        'scheduled_at' => '2026-07-01 10:00:00',
    ]);

    expect(Appointment::conflictsWith(Carbon::parse('2026-07-01 10:10:00')))->toBeFalse();
});

test('create appointment page prefills scheduled_at from the query string', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['scheduled_at' => '2026-07-15 14:00:00'])
        ->test(CreateAppointment::class)
        ->assertSet('data.scheduled_at', '2026-07-15 14:00:00');
});
