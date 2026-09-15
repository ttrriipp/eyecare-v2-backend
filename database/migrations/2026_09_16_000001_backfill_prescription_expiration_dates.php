<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('prescriptions')
            ->select(['id', 'prescribed_at'])
            ->whereNull('expires_at')
            ->orderBy('id')
            ->chunkById(500, function (Collection $prescriptions): void {
                $prescriptions->each(function (object $prescription): void {
                    DB::table('prescriptions')
                        ->where('id', $prescription->id)
                        ->update([
                            'expires_at' => Carbon::parse($prescription->prescribed_at)
                                ->addMonthsNoOverflow(6)
                                ->toDateString(),
                        ]);
                });
            });
    }

    public function down(): void
    {
        // Existing expiration dates must not be discarded during rollback.
    }
};
