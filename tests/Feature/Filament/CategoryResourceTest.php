<?php

use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Models\ProductCategory;
use App\Models\User;
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
    'staff' => ['staff'],
]);

test('staff can create a category', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateProductCategory::class)
        ->fillForm(['name' => 'Eyeglasses'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(ProductCategory::class, ['name' => 'Eyeglasses']);
});

test('staff can edit a category', function () {
    $staff = User::factory()->staff()->create();
    $category = ProductCategory::factory()->create(['name' => 'Old Category']);

    $this->actingAs($staff);

    Livewire::test(EditProductCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['name' => 'Updated Category'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ProductCategory::class, ['id' => $category->id, 'name' => 'Updated Category']);
});

test('category name is required', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateProductCategory::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

test('category name must be unique', function () {
    $staff = User::factory()->staff()->create();
    ProductCategory::factory()->create(['name' => 'Sunglasses']);

    $this->actingAs($staff);

    Livewire::test(CreateProductCategory::class)
        ->fillForm(['name' => 'Sunglasses'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});
