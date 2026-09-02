<?php

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages\CreateAppointmentTypes;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages\EditAppointmentTypes;
use App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages\ListAppointmentTypes;
use App\Models\AppointmentType;
use App\Models\AppointmentTypeVisitReasonPreset;
use App\Models\User;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
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

test('appointment type edit page has no delete action', function () {
    $this->actingAs($this->admin);

    $appointmentType = AppointmentType::query()->firstOrFail();

    Livewire::test(EditAppointmentTypes::class, ['record' => $appointmentType->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

test('appointment type create page renders for admins', function () {
    $this->actingAs($this->admin);

    $this->get('/admin/availability/appointment-types/create')
        ->assertSuccessful();
});

test('appointment type form uses a responsive section layout', function () {
    $schema = AppointmentTypesResource::form(Schema::make());
    $grid = $schema->getComponents()[0];
    $sections = $grid->getDefaultChildComponents();

    expect($grid)
        ->toBeInstanceOf(Grid::class)
        ->and($grid->getColumns('default'))
        ->toBe(1)
        ->and($grid->getColumns('lg'))
        ->toBe(2)
        ->and($grid->getColumnSpan('default'))
        ->toBe('full')
        ->and($grid->getColumnSpan('lg'))
        ->toBe('full')
        ->and($sections)
        ->toHaveCount(3);

    expect($sections[0])
        ->toBeInstanceOf(Section::class)
        ->and($sections[0]->getColumnSpan('lg'))
        ->toBe(1)
        ->and($sections[1])
        ->toBeInstanceOf(Section::class)
        ->and($sections[1]->getColumnSpan('lg'))
        ->toBe(1)
        ->and($sections[2])
        ->toBeInstanceOf(Section::class)
        ->and($sections[2]->getColumnSpan('default'))
        ->toBe('full')
        ->and($sections[2]->getColumnSpan('lg'))
        ->toBe(2);
});

test('appointment type form provides a reorderable visit reason preset repeater', function () {
    $this->actingAs($this->admin);

    $schema = Livewire::test(CreateAppointmentTypes::class)->instance()->form;
    $grid = $schema->getComponents()[0];
    $presetSection = $grid->getDefaultChildComponents()[2];
    $repeater = $presetSection->getDefaultChildComponents()[0];
    $activeToggle = $repeater->getDefaultChildComponents()[1];

    expect($repeater)
        ->toBeInstanceOf(Repeater::class)
        ->and($repeater->getName())
        ->toBe('visit_reason_presets')
        ->and($activeToggle)
        ->toBeInstanceOf(Toggle::class)
        ->and($activeToggle->getName())
        ->toBe('is_active')
        ->and($activeToggle->isInline())
        ->toBeFalse();
});

test('admins can create visit reason presets from an appointment type form', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateAppointmentTypes::class)
        ->fillForm([
            'name' => 'Preset-enabled type',
            'duration_minutes' => 30,
            'visit_reason_presets' => [
                ['label' => '  Blurred vision  ', 'is_active' => true],
                ['label' => 'Eye discomfort', 'is_active' => true],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $appointmentType = AppointmentType::query()->where('name', 'Preset-enabled type')->firstOrFail();

    expect($appointmentType->visitReasonPresets()->orderBy('sort_order')->pluck('label')->all())
        ->toBe(['Blurred vision', 'Eye discomfort']);
});

test('admins can edit, deactivate, and reorder visit reason presets from an appointment type form', function () {
    $appointmentType = AppointmentType::factory()->create();
    $first = AppointmentTypeVisitReasonPreset::factory()->for($appointmentType)->create([
        'label' => 'First reason',
        'sort_order' => 0,
    ]);
    $second = AppointmentTypeVisitReasonPreset::factory()->for($appointmentType)->create([
        'label' => 'Second reason',
        'sort_order' => 1,
    ]);

    $this->actingAs($this->admin);

    $component = Livewire::test(EditAppointmentTypes::class, ['record' => $appointmentType->getRouteKey()]);
    $presetState = $component->get('data.visit_reason_presets');
    $reorderedState = array_reverse($presetState, true);
    $firstStateKey = array_key_first($presetState);
    $reorderedState[$firstStateKey]['label'] = 'Updated reason';
    $reorderedState[$firstStateKey]['is_active'] = false;

    $component
        ->set('data.visit_reason_presets', $reorderedState)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($first->fresh()->label)->toBe('Updated reason')
        ->and($first->fresh()->is_active)->toBeFalse()
        ->and($second->fresh()->sort_order)->toBeLessThan($first->fresh()->sort_order);
});
