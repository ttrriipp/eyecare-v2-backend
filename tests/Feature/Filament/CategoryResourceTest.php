<?php

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff and admin can list categories', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $categories = Category::factory()->count(2)->create();

    $this->actingAs($user);

    Livewire::test(ListCategories::class)
        ->assertCanSeeTableRecords($categories);
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('staff can create a category', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Eyeglasses'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Category::class, ['name' => 'Eyeglasses']);
});

test('staff can edit a category', function () {
    $staff = User::factory()->staff()->create();
    $category = Category::factory()->create(['name' => 'Old Category']);

    $this->actingAs($staff);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['name' => 'Updated Category'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Category::class, ['id' => $category->id, 'name' => 'Updated Category']);
});

test('category name is required', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

test('category name must be unique', function () {
    $staff = User::factory()->staff()->create();
    Category::factory()->create(['name' => 'Sunglasses']);

    $this->actingAs($staff);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Sunglasses'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});
