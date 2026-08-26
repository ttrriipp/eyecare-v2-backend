<?php

use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\RelationManagers\PreferredFramesRelationManager;
use App\Models\Brand;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->brand = Brand::factory()->create();
    $this->frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
});

test('linked patient record shows preferred frames', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertCanSeeTableRecords([$variant->savedFrames()->first()]);
});

test('unlinked patient record shows no preferred frames', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create(['user_id' => null]);

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertCanNotSeeTableRecords([]);
});

test('preferred frames are ordered newest first', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant1 = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
    ]);
    $variant2 = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
    ]);

    $old = SavedFrame::factory()->forAccount($user)->forVariant($variant1)->create([
        'created_at' => now()->subDay(),
    ]);
    $new = SavedFrame::factory()->forAccount($user)->forVariant($variant2)->create([
        'created_at' => now(),
    ]);

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertCanSeeTableRecords([$new, $old]);
});

test('preferred frames shows availability badge for active variant', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertSee('Available');
});

test('preferred frames shows out of stock badge', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
        'stock_quantity' => 0,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertSee('Out of stock');
});

test('preferred frames shows inactive badge for deactivated variant', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => false,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertSee('Inactive');
});

test('preferred frames relation manager has no mutation actions', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $this->actingAs($staff);

    $component = Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ]);

    // No create, edit, or delete actions should exist
    $component->assertTableBulkActionDoesNotExist('delete');
});
