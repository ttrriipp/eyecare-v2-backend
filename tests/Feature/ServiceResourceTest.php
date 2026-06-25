<?php

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('admin can list services', function () {
    $services = Service::factory()->count(3)->create();

    Livewire::test(ListServices::class)
        ->assertCanSeeTableRecords($services);
});

test('admin can create a service', function () {
    Livewire::test(CreateService::class)
        ->fillForm([
            'name' => 'Glaucoma Screening',
            'price' => 450.00,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Service::class, [
        'name' => 'Glaucoma Screening',
        'price' => '450.00',
        'is_active' => true,
    ]);
});

test('admin can edit a service', function () {
    $service = Service::factory()->create(['price' => '300.00']);

    Livewire::test(EditService::class, ['record' => $service->id])
        ->fillForm(['price' => 350.00])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($service->fresh()->price)->toBe('350.00');
});

test('service name must be unique', function () {
    Service::factory()->create(['name' => 'Eye Exam']);

    Livewire::test(CreateService::class)
        ->fillForm(['name' => 'Eye Exam', 'price' => 500.00])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

test('staff cannot access services', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $this->get(ServiceResource::getUrl('index'))->assertForbidden();
});
