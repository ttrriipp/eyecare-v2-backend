<?php

use App\Enums\BillingItemSourceKind;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Models\BillingRecordItem;
use App\Models\Encounter;

/**
 * @return array<int, string>
 */
function userFacingEncounterStrings(string $source): array
{
    $encounterStrings = [];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        $value = stripcslashes(substr($token[1], 1, -1));

        if (preg_match('/encounter/i', $value) !== 1) {
            continue;
        }

        if (preg_match('/\s|^Encounter$/', $value) === 1) {
            $encounterStrings[] = $value;
        }
    }

    return $encounterStrings;
}

test('presentation strings use consultation terminology without renaming contracts', function () {
    $presentationFiles = [
        app_path('Filament/Resources/Encounters/EncounterResource.php'),
        app_path('Filament/Resources/Encounters/Pages/EditEncounter.php'),
        app_path('Filament/Resources/Encounters/Schemas/EncounterForm.php'),
        app_path('Filament/Resources/Encounters/Tables/EncountersTable.php'),
        app_path('Filament/Resources/Appointments/Pages/EditAppointment.php'),
        app_path('Filament/Resources/Appointments/Tables/AppointmentsTable.php'),
        app_path('Filament/Resources/Patients/RelationManagers/EncountersRelationManager.php'),
        app_path('Filament/Resources/Patients/RelationManagers/PrescriptionsRelationManager.php'),
        app_path('Filament/Resources/Prescriptions/Pages/ViewPrescription.php'),
        app_path('Filament/Resources/Prescriptions/Pages/CreatePrescription.php'),
        app_path('Filament/Resources/Prescriptions/Pages/AmendPrescription.php'),
        app_path('Filament/Resources/Prescriptions/Tables/PrescriptionsTable.php'),
        app_path('Filament/Resources/Quotations/Pages/CreateQuotation.php'),
        app_path('Filament/Resources/OpticalOrders/Pages/CreateDirectOpticalOrder.php'),
        app_path('Filament/Pages/Dashboard.php'),
        app_path('Filament/Widgets/StatsOverviewWidget.php'),
        app_path('Filament/Widgets/EncounterStatsWidget.php'),
        app_path('Actions/Encounters/AssignEncounterOptometrist.php'),
        app_path('Actions/Encounters/StartEncounter.php'),
        app_path('Actions/Encounters/SaveEncounterDraft.php'),
        app_path('Actions/Encounters/CreateEncounterAddendum.php'),
        app_path('Actions/Encounters/CompleteEncounter.php'),
        app_path('Actions/Encounters/TransferEncounter.php'),
        app_path('Actions/Encounters/VoidEncounter.php'),
        app_path('Actions/Prescriptions/FinalizePrescription.php'),
        app_path('Actions/Quotations/CreateQuotation.php'),
    ];

    foreach ($presentationFiles as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toContain('Consulation')
            ->and(userFacingEncounterStrings($source))->toBe([]);
    }

    $billingTable = file_get_contents(app_path('Filament/Resources/BillingRecords/Tables/BillingRecordsTable.php'));

    expect($billingTable)->not->toContain("->label('Encounter')")
        ->not->toContain("'encounter' => 'Encounter'");

    $printView = file_get_contents(resource_path('views/filament/encounters/print.blade.php'));

    expect($printView)->not->toContain('Encounter')
        ->not->toContain('Consulation');

    expect(EncounterResource::getModel())->toBe(Encounter::class)
        ->and(EncounterResource::getUrl('index'))->toContain('/admin/encounters')
        ->and(new BillingRecordItem(['source_kind' => BillingItemSourceKind::Encounter])->getSourceLabel())->toBe('Consultation');
});
