<?php

use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff and admin can list categories', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $categories = ProductCategory::factory()->count(2)->create();

    $this->actingAs($user);

    Livewire::test(ListProductCategories::class)
        ->assertCanSeeTableRecords($categories);
})->with([
    'admin' => ['admin'],
]);

test('admin can create a category', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateProductCategory::class)
        ->fillForm(['name' => 'Eyeglasses'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(ProductCategory::class, ['name' => 'Eyeglasses']);
});

test('admin can edit a category', function () {
    $admin = User::factory()->admin()->create();
    $category = ProductCategory::factory()->create(['name' => 'Old Category']);

    $this->actingAs($admin);

    Livewire::test(EditProductCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['name' => 'Updated Category'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ProductCategory::class, ['id' => $category->id, 'name' => 'Updated Category']);
});

test('admin can deactivate and reactivate categories through the lifecycle actions', function () {
    $admin = User::factory()->admin()->create();
    $active = ProductCategory::factory()->create(['name' => 'Active Category']);
    $inactive = ProductCategory::factory()->create(['name' => 'Inactive Category', 'is_active' => false]);

    $this->actingAs($admin);

    $component = Livewire::test(ListProductCategories::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive])
        ->callAction(TestAction::make('deactivate')->table($active))
        ->assertNotified();

    expect($active->fresh()->is_active)->toBeFalse();

    $component
        ->filterTable('catalog_status', 'inactive')
        ->assertCanSeeTableRecords([$active, $inactive])
        ->callAction(TestAction::make('activate')->table($inactive))
        ->assertNotified()
        ->filterTable('catalog_status', 'all')
        ->assertCanSeeTableRecords([$active, $inactive]);

    expect($inactive->fresh()->is_active)->toBeTrue();
});

test('category name is required', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateProductCategory::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

test('category name must be unique', function () {
    $admin = User::factory()->admin()->create();
    ProductCategory::factory()->create(['name' => 'Sunglasses']);

    $this->actingAs($admin);

    Livewire::test(CreateProductCategory::class)
        ->fillForm(['name' => 'Sunglasses'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

test('staff cannot access categories', function () {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff);

    $this->get(ProductCategoryResource::getUrl('index'))->assertForbidden();
});
