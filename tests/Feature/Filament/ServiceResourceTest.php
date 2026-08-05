<?php

use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('admin can list services', function () {
    $admin = User::factory()->admin()->create();
    $services = Service::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListServices::class)
        ->assertCanSeeTableRecords($services);
});

test('staff cannot view the service catalog', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $this->get(ServiceResource::getUrl('index'))
        ->assertForbidden();
});

test('admin can create a service', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateService::class)
        ->fillForm([
            'name' => 'Comprehensive Eye Exam',
            'price' => 500,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Service::query()->where('name', 'Comprehensive Eye Exam')->exists())->toBeTrue();
});

test('admin can edit a service', function () {
    $admin = User::factory()->admin()->create();
    $service = Service::factory()->create(['price' => 500]);

    $this->actingAs($admin);

    Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
        ->fillForm(['price' => 750])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($service->fresh()->price)->toEqualWithDelta(750.0, 0.001);
});

test('a service name must be unique', function () {
    $admin = User::factory()->admin()->create();
    Service::factory()->create(['name' => 'Comprehensive Eye Exam']);

    $this->actingAs($admin);

    Livewire::test(CreateService::class)
        ->fillForm([
            'name' => 'Comprehensive Eye Exam',
            'price' => 500,
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});
