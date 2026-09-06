<?php

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Tables\AppointmentTypesTable;
use App\Filament\Resources\AppointmentRequests\Tables\AppointmentRequestsTable;
use App\Filament\Resources\Appointments\Tables\AppointmentsTable;
use App\Filament\Resources\AuditLogs\Tables\AuditLogsTable;
use App\Filament\Resources\BillingRecords\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\BillingRecords\Tables\BillingRecordsTable;
use App\Filament\Resources\Brands\Tables\BrandsTable;
use App\Filament\Resources\Encounters\Tables\EncountersTable;
use App\Filament\Resources\FrameRatings\Tables\FrameRatingsTable;
use App\Filament\Resources\Inventory\Tables\InventoryTable;
use App\Filament\Resources\InventoryMovements\Tables\InventoryMovementsTable;
use App\Filament\Resources\LensCategories\Tables\LensCategoriesTable;
use App\Filament\Resources\LensOptions\Tables\LensOptionsTable;
use App\Filament\Resources\OpticalOrders\Tables\OpticalOrdersTable;
use App\Filament\Resources\PatientAccounts\RelationManagers\DeviceSessionsRelationManager;
use App\Filament\Resources\PatientAccounts\RelationManagers\LinkRequestsRelationManager;
use App\Filament\Resources\PatientAccounts\Tables\PatientAccountsTable;
use App\Filament\Resources\PatientLinkRequests\Tables\PatientLinkRequestsTable;
use App\Filament\Resources\Patients\RelationManagers\AppointmentsRelationManager;
use App\Filament\Resources\Patients\RelationManagers\EncountersRelationManager;
use App\Filament\Resources\Patients\RelationManagers\InvitationHistoryRelationManager;
use App\Filament\Resources\Patients\RelationManagers\PreferredFramesRelationManager;
use App\Filament\Resources\Patients\RelationManagers\PrescriptionsRelationManager;
use App\Filament\Resources\Patients\Tables\PatientsTable;
use App\Filament\Resources\Prescriptions\Tables\PrescriptionsTable;
use App\Filament\Resources\ProductCategories\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\ProductCategories\Tables\ProductCategoriesTable;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Filament\Resources\Quotations\Tables\QuotationsTable;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\VisitRatings\Tables\VisitRatingsTable;
use App\Filament\Widgets\TodaysScheduleWidget;
use App\Models\User;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('appointment types use regular font weight in the appointments table', function () {
    $this->actingAs(User::factory()->create());
    $table = AppointmentsTable::configure(Table::make(Mockery::mock(HasTable::class)));

    expect($table->getColumn('appointmentType.name')->getWeight())->toBeNull();
});

test('admin tables emphasize record names except optometrists', function (string $class, array $names) {
    $this->actingAs(User::factory()->create());
    $table = Table::make(Mockery::mock(HasTable::class));
    $table = method_exists($class, 'configure')
        ? $class::configure($table)
        : (new $class)->table($table);

    foreach ($names as $name) {
        $isOptometrist = in_array($name, ['optometrist.first_name', 'optometrist.full_name', 'author.first_name'], true);

        expect($table->getColumn($name)->getWeight())->toBe($isOptometrist ? null : FontWeight::Bold);
    }
})->with([
    'App\\Filament\\Resources\\LensCategories\\Tables\\LensCategoriesTable' => [LensCategoriesTable::class, ['name']],
    'App\\Filament\\Resources\\VisitRatings\\Tables\\VisitRatingsTable' => [VisitRatingsTable::class, ['patient.full_name', 'optometrist.full_name']],
    'App\\Filament\\Resources\\Quotations\\Tables\\QuotationsTable' => [QuotationsTable::class, ['patient.full_name']],
    'App\\Filament\\Resources\\BillingRecords\\Tables\\BillingRecordsTable' => [BillingRecordsTable::class, ['patient.full_name']],
    'App\\Filament\\Resources\\BillingRecords\\RelationManagers\\PaymentsRelationManager' => [PaymentsRelationManager::class, ['recordedBy.first_name']],
    'App\\Filament\\Resources\\AppointmentRequests\\Tables\\AppointmentRequestsTable' => [AppointmentRequestsTable::class, ['patient.full_name']],
    'App\\Filament\\Resources\\Patients\\Tables\\PatientsTable' => [PatientsTable::class, ['full_name']],
    'App\\Filament\\Resources\\Patients\\RelationManagers\\EncountersRelationManager' => [EncountersRelationManager::class, ['optometrist.first_name']],
    'App\\Filament\\Resources\\Patients\\RelationManagers\\PrescriptionsRelationManager' => [PrescriptionsRelationManager::class, ['author.first_name']],
    'App\\Filament\\Resources\\Patients\\RelationManagers\\PreferredFramesRelationManager' => [PreferredFramesRelationManager::class, ['variant.product.name', 'variant.name']],
    'App\\Filament\\Resources\\Patients\\RelationManagers\\InvitationHistoryRelationManager' => [InvitationHistoryRelationManager::class, ['sender.first_name']],
    'App\\Filament\\Resources\\Patients\\RelationManagers\\AppointmentsRelationManager' => [AppointmentsRelationManager::class, ['appointmentType.name']],
    'App\\Filament\\Resources\\Prescriptions\\Tables\\PrescriptionsTable' => [PrescriptionsTable::class, ['patient.full_name', 'author.first_name']],
    'App\\Filament\\Resources\\PatientLinkRequests\\Tables\\PatientLinkRequestsTable' => [PatientLinkRequestsTable::class, ['user.first_name', 'reviewer.first_name']],
    'App\\Filament\\Resources\\Products\\Tables\\ProductsTable' => [ProductsTable::class, ['name', 'brand.name', 'category.name']],
    'App\\Filament\\Resources\\Products\\RelationManagers\\VariantsRelationManager' => [VariantsRelationManager::class, ['name']],
    'App\\Filament\\Resources\\OpticalOrders\\Tables\\OpticalOrdersTable' => [OpticalOrdersTable::class, ['patient.full_name']],
    'App\\Filament\\Resources\\LensOptions\\Tables\\LensOptionsTable' => [LensOptionsTable::class, ['name']],
    'App\\Filament\\Resources\\FrameRatings\\Tables\\FrameRatingsTable' => [FrameRatingsTable::class, ['patient.first_name', 'variant.name']],
    'App\\Filament\\Resources\\InventoryMovements\\Tables\\InventoryMovementsTable' => [InventoryMovementsTable::class, ['variant.product.name', 'variant.name', 'createdBy.first_name']],
    'App\\Filament\\Resources\\ProductCategories\\Tables\\ProductCategoriesTable' => [ProductCategoriesTable::class, ['name']],
    'App\\Filament\\Resources\\ProductCategories\\RelationManagers\\ProductsRelationManager' => [ProductsRelationManager::class, ['name']],
    'App\\Filament\\Resources\\Users\\Tables\\UsersTable' => [UsersTable::class, ['first_name']],
    'App\\Filament\\Resources\\Encounters\\Tables\\EncountersTable' => [EncountersTable::class, ['patient.full_name', 'optometrist.first_name']],
    'App\\Filament\\Resources\\Appointments\\Tables\\AppointmentsTable' => [AppointmentsTable::class, ['patient.full_name', 'optometrist.first_name', 'createdBy.first_name']],
    'App\\Filament\\Resources\\Brands\\Tables\\BrandsTable' => [BrandsTable::class, ['name']],
    'App\\Filament\\Resources\\Brands\\RelationManagers\\ProductsRelationManager' => [App\Filament\Resources\Brands\RelationManagers\ProductsRelationManager::class, ['name']],
    'App\\Filament\\Resources\\Inventory\\Tables\\InventoryTable' => [InventoryTable::class, ['product.name', 'name']],
    'App\\Filament\\Resources\\PatientAccounts\\Tables\\PatientAccountsTable' => [PatientAccountsTable::class, ['name']],
    'App\\Filament\\Resources\\PatientAccounts\\RelationManagers\\DeviceSessionsRelationManager' => [DeviceSessionsRelationManager::class, ['name']],
    'App\\Filament\\Resources\\PatientAccounts\\RelationManagers\\LinkRequestsRelationManager' => [LinkRequestsRelationManager::class, ['reviewedPatient.full_name', 'reviewer.first_name']],
    'App\\Filament\\Resources\\AuditLogs\\Tables\\AuditLogsTable' => [AuditLogsTable::class, ['actor.first_name']],
    'App\\Filament\\Resources\\Services\\Tables\\ServicesTable' => [ServicesTable::class, ['name']],
    'App\\Filament\\Clusters\\Availability\\Resources\\AppointmentTypes\\Tables\\AppointmentTypesTable' => [AppointmentTypesTable::class, ['name']],
    'App\\Filament\\Widgets\\TodaysScheduleWidget' => [TodaysScheduleWidget::class, ['patient.full_name', 'appointmentType.name', 'optometrist.full_name']],
]);
