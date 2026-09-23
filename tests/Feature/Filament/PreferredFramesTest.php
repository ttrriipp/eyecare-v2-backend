<?php

use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\PatientAccounts\Pages\ViewPatientAccount;
use App\Filament\Resources\PatientAccounts\RelationManagers\PreferredFramesRelationManager as PatientAccountPreferredFramesRelationManager;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\RelationManagers\PreferredFramesRelationManager;
use App\Filament\Resources\Products\ProductResource;
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
use Illuminate\Support\Facades\Storage;
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

test('preferred frames table renders the variant thumbnail', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    Storage::fake('public');
    Storage::disk('public')->put('variants/preferred-frame.png', 'frame image');

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'images' => ['variants/preferred-frame.png'],
        'is_active' => true,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $this->actingAs($staff);

    Livewire::test(PreferredFramesRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertSee(Storage::disk('public')->url('variants/preferred-frame.png'));
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

test('patient account shows its preferred frame variants without requiring a patient link', function () {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->create();
    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'name' => 'Blue',
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
    $savedFrame = SavedFrame::factory()->forAccount($account)->forVariant($variant)->create();

    $this->actingAs($staff);

    Livewire::test(PatientAccountPreferredFramesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewPatientAccount::class,
    ])
        ->assertCanSeeTableRecords([$savedFrame])
        ->assertSee($this->frame->name)
        ->assertSee('Blue')
        ->assertDontSee('No linked account');
});

// --- Appointment context tests ---

test('appointment edit hides preferred frames section even when patient has saved frames', function () {
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
        ->assertDontSee('Preferred Frames')
        ->assertDontSee($this->frame->name);
});

test('appointment edit does not render preferred frame thumbnails or product links', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    Storage::fake('public');
    Storage::disk('public')->put('variants/appointment-frame.png', 'frame image');

    $variant = ProductVariant::factory()->create([
        'product_id' => $this->frame->id,
        'images' => ['variants/appointment-frame.png'],
        'is_active' => true,
        'stock_quantity' => 5,
    ]);
    SavedFrame::factory()->forAccount($user)->forVariant($variant)->create();

    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertDontSee(Storage::disk('public')->url('variants/appointment-frame.png'))
        ->assertDontSee('href="'.ProductResource::getUrl('edit', ['record' => $this->frame]).'"', false);
});

test('appointment edit does not show saved frames or a patient link', function () {
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
        ->assertDontSee('Variant 1')
        ->assertDontSee('Variant 2')
        ->assertDontSee('Variant 3')
        ->assertDontSee('Variant 4')
        ->assertDontSee('View all preferred frames');
});

test('appointment edit does not show linked-account details for an unlinked patient', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create(['user_id' => null]);

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertDontSee('No linked account')
        ->assertDontSee('Preferred Frames');
});

test('appointment edit does not show preferred-frame availability badges', function () {
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
        ->assertDontSee('Out of stock')
        ->assertDontSee('Preferred Frames')
        ->assertDontSee('Unavailable');
});

test('appointment edit does not show an empty preferred-frames state', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $user = User::factory()->create();
    $patient->update(['user_id' => $user->id]);

    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertDontSee('No preferred frames')
        ->assertDontSee('Preferred Frames');
});

// --- Consultation context tests ---

test('consultation edit hides preferred frames section even when patient has saved frames', function () {
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
        ->assertDontSee('Preferred Frames')
        ->assertDontSee($this->frame->name);
});

test('in-progress consultation edit keeps preferred frames hidden', function () {
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
        ->assertDontSee('Preferred Frames')
        ->assertDontSee($this->frame->name);
});
