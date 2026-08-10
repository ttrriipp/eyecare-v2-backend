<?php

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages\ListAppointmentTypes;
use App\Models\User;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
});

test('admins can access appointment types list', function () {
    $this->actingAs($this->admin);

    $this->get('/admin/availability/appointment-types')
        ->assertSuccessful();
});

test('staff cannot access appointment types', function () {
    $this->actingAs($this->staff);

    $this->get('/admin/availability/appointment-types')
        ->assertForbidden();
});

test('optometrists cannot access appointment types', function () {
    $this->actingAs($this->optometrist);

    $this->get('/admin/availability/appointment-types')
        ->assertForbidden();
});

test('appointment types table renders for admins', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListAppointmentTypes::class)
        ->assertSuccessful();
});

test('appointment type create page renders for admins', function () {
    $this->actingAs($this->admin);

    $this->get('/admin/availability/appointment-types/create')
        ->assertSuccessful();
});

test('appointment type form sections span the full content width', function () {
    $schema = AppointmentTypesResource::form(Schema::make());
    $grid = $schema->getComponents()[0];
    $sections = $grid->getDefaultChildComponents();

    expect($grid)
        ->toBeInstanceOf(Grid::class)
        ->and($sections)
        ->toHaveCount(2);

    foreach ($sections as $section) {
        expect($section)
            ->toBeInstanceOf(Section::class)
            ->and($section->getColumnSpan('default'))
            ->toBe('full');
    }
});
