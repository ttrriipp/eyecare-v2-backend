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

test('service prices reject negative values and accept decimals', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(CreateService::class)
        ->fillForm([
            'name' => 'Negative Price Service',
            'price' => -0.01,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['price' => 'min']);

    Livewire::test(CreateService::class)
        ->fillForm([
            'name' => 'Decimal Price Service',
            'price' => 500.25,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((float) Service::query()->where('name', 'Decimal Price Service')->value('price'))
        ->toBe(500.25);
});

test('service price input renders decimal constraints without spinner controls', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(Livewire::test(CreateService::class)->html())
        ->toContain('service-price-input')
        ->toContain('min="0"')
        ->toContain('step="0.01"')
        ->toContain('type="number"');
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

test('admin can reactivate an inactive service from its edit page', function () {
    $admin = User::factory()->admin()->create();
    $service = Service::factory()->create(['is_active' => false]);

    $this->actingAs($admin);

    Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionVisible('activate')
        ->callAction('activate')
        ->assertNotified();

    expect($service->fresh()->is_active)->toBeTrue();
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
