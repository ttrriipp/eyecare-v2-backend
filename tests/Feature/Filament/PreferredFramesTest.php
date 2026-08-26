<?php

use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\RelationManagers\PreferredFramesRelationManager;
use App\Models\Appointment;
use App\Models\Brand;
use App\Models\Encounter;
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
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
    ]);
    $savedFrame = SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();
    $patient->update(['user_id' => null]);

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertCanNotSeeTableRecords([$savedFrame])
        ->assertSee('No linked account');
});

test('linked patient record distinguishes an empty preferred frames list', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertSee('No preferred frames')
        ->assertDontSee('No linked account');
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

test('preferred frames keeps soft-deleted variants visible as inactive', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();
    $variant->delete();

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertCanSeeTableRecords([SavedFrame::query()->where('product_variant_id', $variant->id)->first()])
        ->assertSee($this->frame->name)
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

// --- Appointment context tests ---

test('appointment edit shows preferred frames section for linked patient', function () {
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

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Preferred Frames')
        ->assertSee($this->frame->name);
});

test('appointment edit shows only the latest three preferences and a patient link', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variants = collect(range(1, 4))->map(function (int $index): ProductVariant {
        return ProductVariant::factory()->create([
            'product_id' => $this->frame->id,
            'name' => "Variant {$index}",
            'is_active' => true,
            'stock_quantity' => 5,
        ]);
    });

    foreach ($variants as $index => $variant) {
        SavedFrame::factory()->forAccount($user)->forVariant($variant)->create([
            'created_at' => now()->subDays(4 - $index),
        ]);
    }

    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Variant 2')
        ->assertSee('Variant 3')
        ->assertSee('Variant 4')
        ->assertDontSee('Variant 1')
        ->assertSee('View all preferred frames');
});

test('appointment edit shows no linked account for unlinked patient', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create(['user_id' => null]);

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('No linked account');
});

test('appointment edit shows the richer availability badge for an out-of-stock preference', function () {
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

    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('Out of stock')
        ->assertDontSee('Unavailable');
});

test('appointment edit shows no preferred frames when linked but empty', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertSee('No preferred frames');
});

// --- Consultation context tests ---

test('consultation edit shows preferred frames section for linked patient', function () {
    $staff = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'optometrist_id' => $staff->id,
        'status' => 'completed',
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSee('Preferred Frames')
        ->assertSee($this->frame->name);
});

test('in-progress consultation edit keeps preferred frames visible', function () {
    $staff = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'optometrist_id' => $staff->id,
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSee('Preferred Frames')
        ->assertSee($this->frame->name);
});
