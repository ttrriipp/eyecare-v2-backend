<?php

namespace App\Actions\Encounters;

use App\Models\Encounter;
use App\Models\PatientIntake;

class AuditLegacyPatientIntakes
{
    public function handle(): array
    {
        $totalIntakes = PatientIntake::count();

        $activeIntakes = PatientIntake::query()
            ->whereHas('appointment', function ($query) {
                $query->whereHas('status', function ($statusQuery) {
                    $statusQuery->whereIn('name', ['scheduled', 'checked_in']);
                });
            })
            ->count();

        $futureEncountersWithIntake = Encounter::query()
            ->whereNotNull('patient_intake_id')
            ->whereHas('appointment', function ($query) {
                $query->where('scheduled_at', '>', now())
                    ->whereHas('status', function ($statusQuery) {
                        $statusQuery->whereIn('name', ['scheduled', 'checked_in']);
                    });
            })
            ->count();

        $encountersWithIntakeData = Encounter::query()
            ->whereNotNull('patient_intake_id')
            ->count();

        $unmigratedEncounters = Encounter::query()
            ->whereNull('chief_complaint')
            ->whereNotNull('patient_intake_id')
            ->whereHas('intake', function ($query) {
                $query->whereNotNull('chief_complaint');
            })
            ->count();

        return [
            'total_intakes' => $totalIntakes,
            'active_intakes' => $activeIntakes,
            'future_encounters_with_intake' => $futureEncountersWithIntake,
            'encounters_with_intake_data' => $encountersWithIntakeData,
            'unmigrated_encounters' => $unmigratedEncounters,
            'cleanup_ready' => $activeIntakes === 0 && $futureEncountersWithIntake === 0,
        ];
    }
}
