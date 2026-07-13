<?php

use App\Actions\Appointments\LockAppointmentScheduleDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

test('appointment schedule date locks create and reuse one row per clinic date', function () {
    $firstLock = DB::transaction(
        fn (): object => app(LockAppointmentScheduleDate::class)->handle(Carbon::parse('2026-07-13 10:00:00')),
    );
    $secondLock = DB::transaction(
        fn (): object => app(LockAppointmentScheduleDate::class)->handle('2026-07-13'),
    );

    expect($firstLock->schedule_date)->toBe('2026-07-13')
        ->and($secondLock->id)->toBe($firstLock->id);

    assertDatabaseCount('appointment_schedule_locks', 1);
    assertDatabaseHas('appointment_schedule_locks', [
        'schedule_date' => '2026-07-13',
    ]);
});
