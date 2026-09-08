<?php

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages\ListAppointmentTypes;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\LensCategories\Pages\ListLensCategories;
use App\Filament\Resources\LensOptions\Pages\ListLensOptions;
use App\Filament\Resources\OpticalOrders\Pages\ListOpticalOrders;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('list actions render as labeled icon buttons in the table toolbar', function (
    string $page,
    string $actionName,
    string $icon,
    ?string $label,
): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test($page)
        ->assertTableActionVisible($actionName)
        ->assertTableActionHasIcon($actionName, $icon)
        ->assertTableActionExists($actionName, function (Action $action) use ($label): bool {
            return $action->isButton()
                && ($label === null || $action->getLabel() === $label)
                && in_array($action, $action->getTable()?->getToolbarActions() ?? [], true);
        });
})->with([
    'appointment types' => [
        ListAppointmentTypes::class,
        'create',
        'heroicon-o-plus-circle',
        null,
    ],
    'patients' => [
        ListPatients::class,
        'create',
        'heroicon-o-plus-circle',
        'New Patient',
    ],
    'appointments calendar' => [
        ListAppointments::class,
        'calendar',
        'heroicon-o-calendar-days',
        'Calendar',
    ],
    'appointment requests' => [
        ListAppointments::class,
        'requests',
        'heroicon-o-clock',
        'Requests',
    ],
    'appointments create' => [
        ListAppointments::class,
        'create',
        'heroicon-o-plus-circle',
        null,
    ],
    'lens options' => [
        ListLensOptions::class,
        'create',
        'heroicon-o-plus-circle',
        null,
    ],
    'optical orders' => [
        ListOpticalOrders::class,
        'newDirectOrder',
        'heroicon-o-plus-circle',
        'New Direct Order',
    ],
    'users' => [
        ListUsers::class,
        'create',
        'heroicon-o-plus-circle',
        null,
    ],
    'lens categories' => [
        ListLensCategories::class,
        'create',
        'heroicon-o-plus-circle',
        null,
    ],
    'quotations' => [
        ListQuotations::class,
        'create',
        'heroicon-o-plus-circle',
        'New Quotation',
    ],
    'product categories' => [
        ListProductCategories::class,
        'create',
        'heroicon-o-plus-circle',
        null,
    ],
    'brands' => [
        ListBrands::class,
        'create',
        'heroicon-o-plus-circle',
        null,
    ],
    'services' => [
        ListServices::class,
        'create',
        'heroicon-o-plus-circle',
        null,
    ],
]);
