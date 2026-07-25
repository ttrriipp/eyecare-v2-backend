<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

// ─── Access control ───────────────────────────────────────────────────────────

test('admin can access the users list', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertSuccessful();
});

test('staff cannot access the users list', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(ListUsers::class)
        ->assertForbidden();
});

// ─── List ─────────────────────────────────────────────────────────────────────

test('admin can see all users in the table', function () {
    $users = User::factory()->count(3)->staff()->create();

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

test('user table identifies optometrists', function () {
    $optometrist = User::factory()->staff()->create([
        'is_optometrist' => true,
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableColumnStateSet('is_optometrist', true, record: $optometrist);
});

test('table can be filtered by role', function () {
    $staffRole = Role::where('name', 'staff')->first();
    $patientRole = Role::where('name', 'patient')->first();

    $staff = User::factory()->staff()->create();
    $patient = User::factory()->patient()->create();

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->filterTable('role', $staffRole->id)
        ->assertCanSeeTableRecords([$staff])
        ->assertCanNotSeeTableRecords([$patient]);
});

// ─── Create ───────────────────────────────────────────────────────────────────

test('admin can create a user', function () {
    $staffRole = Role::where('name', 'staff')->first();

    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Staff Member',
            'email' => 'newstaff@example.com',
            'phone' => '09171234567',
            'role_id' => $staffRole->id,
            'password' => 'password',
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(User::class, [
        'name' => 'New Staff Member',
        'email' => 'newstaff@example.com',
        'role_id' => $staffRole->id,
    ]);
});

test('admin can create an optometrist', function () {
    $staffRole = Role::where('name', 'staff')->firstOrFail();

    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Clinic Optometrist',
            'email' => 'optometrist@example.com',
            'phone' => '09171234568',
            'role_id' => $staffRole->id,
            'password' => 'password',
            'is_optometrist' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'optometrist@example.com')->firstOrFail()->is_optometrist)->toBeTrue();
});

test('create form requires name, phone, role, and password', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => null,
            'phone' => null,
            'role_id' => null,
            'password' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['name', 'phone', 'role_id', 'password']);
});

// ─── Edit ─────────────────────────────────────────────────────────────────────

test('admin can edit a user name and role', function () {
    $user = User::factory()->staff()->create(['phone' => '09171111111']);
    $adminRole = Role::where('name', 'admin')->first();

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm([
            'name' => 'Updated Name',
            'role_id' => $adminRole->id,
            'phone' => '09171111111',
            'password' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($user->fresh()->name)->toBe('Updated Name')
        ->and($user->fresh()->role->name)->toBe('admin');
});

test('admin can mark an existing staff user as an optometrist', function () {
    $user = User::factory()->staff()->create(['phone' => '09171111112']);

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm([
            'phone' => '09171111112',
            'password' => null,
            'is_optometrist' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->is_optometrist)->toBeTrue();
});

test('optometrist scope excludes customer users even when their flag is set', function () {
    $staffOptometrist = User::factory()->staff()->create(['is_optometrist' => true]);
    $customer = User::factory()->customer()->create(['is_optometrist' => true]);

    expect(User::query()->optometrists()->pluck('id')->all())
        ->toContain($staffOptometrist->id)
        ->not->toContain($customer->id);
});

test('password is not changed when left blank on edit', function () {
    $user = User::factory()->staff()->create(['phone' => '09172222222']);
    $originalHash = $user->password;

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['phone' => '09172222222', 'password' => ''])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($user->fresh()->password)->toBe($originalHash);
});

test('password is updated when provided on edit', function () {
    $user = User::factory()->staff()->create(['phone' => '09173333333']);
    $originalHash = $user->password;

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['phone' => '09173333333', 'password' => 'newpassword123'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($user->fresh()->password)->not->toBe($originalHash);
});

test('created user password can authenticate', function () {
    $staffRole = Role::where('name', 'staff')->first();

    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Login Test User',
            'email' => 'logintest@example.com',
            'phone' => '09179999999',
            'role_id' => $staffRole->id,
            'password' => 'secret123',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'logintest@example.com')->firstOrFail();
    expect(Hash::check('secret123', $user->password))->toBeTrue();
});

test('edit page has no delete action', function () {
    $user = User::factory()->staff()->create();

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->assertActionDoesNotExist('delete');
});
