<?php

namespace App\Actions\Appointments;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

class LockAppointmentScheduleDate
{
    public function handle(CarbonInterface|string $date): stdClass
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Appointment schedule date locks must be acquired inside a database transaction.');
        }

        $scheduleDate = Carbon::parse($date, config('app.timezone'))->toDateString();
        $now = now();

        DB::table('appointment_schedule_locks')->insertOrIgnore([
            'schedule_date' => $scheduleDate,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('appointment_schedule_locks')
            ->where('schedule_date', $scheduleDate)
            ->lockForUpdate()
            ->first();
    }
}
