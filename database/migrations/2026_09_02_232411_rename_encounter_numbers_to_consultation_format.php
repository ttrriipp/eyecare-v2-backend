<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $encounters = DB::table('encounters')
            ->select(['id', 'created_at'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $sequences = [];
        $updates = [];

        foreach ($encounters as $encounter) {
            $year = CarbonImmutable::parse($encounter->created_at)->format('Y');
            $sequence = ($sequences[$year] ?? 0) + 1;
            $sequences[$year] = $sequence;

            $updates[] = [
                'id' => $encounter->id,
                'number' => sprintf('CON-%s-%06d', $year, $sequence),
            ];
        }

        foreach ($updates as $update) {
            DB::table('encounters')
                ->where('id', $update['id'])
                ->update([
                    'encounter_number' => 'TMP-CON-'.$update['id'],
                ]);
        }

        foreach ($updates as $update) {
            DB::table('encounters')
                ->where('id', $update['id'])
                ->update([
                    'encounter_number' => $update['number'],
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Previous values used more than one format and cannot be reconstructed.
    }
};
