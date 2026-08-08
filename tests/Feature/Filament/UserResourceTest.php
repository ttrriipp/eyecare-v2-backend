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

beforeEach(function (): void {
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

test('user table displays roles from pivot', function () {
    $optometrist = User::factory()->optometrist()->create();

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$optometrist]);
});

test('table can be filtered by role', function () {
    $staff = User::factory()->staff()->create();
    $patient = User::factory()->patient()->create();
    $staffRoleId = Role::where('name', Role::Staff)->value('id');

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->filterTable('roles', $staffRoleId)
        ->assertCanSeeTableRecords([$staff])
        ->assertCanNotSeeTableRecords([$patient]);
});

// ─── Create ───────────────────────────────────────────────────────────────────

test('admin can create a user', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => 'New',
            'last_name' => 'Staff Member',
            'email' => 'newstaff@example.com',
            'phone' => '9171234567',
            'roles' => [Role::Staff],
            'password' => 'password',
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $user = User::query()->where('email', 'newstaff@example.com')->firstOrFail();
    expect($user->first_name)->toBe('New')
        ->and($user->roles->pluck('name')->all())->toBe([Role::Staff]);
});

test('admin can create an optometrist', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => 'Clinic',
            'last_name' => 'Optometrist',
            'email' => 'optometrist@example.com',
            'phone' => '9171234568',
            'roles' => [Role::Optometrist],
            'password' => 'password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'optometrist@example.com')->firstOrFail();
    expect($user->isOptometrist())->toBeTrue();
});

test('admin can create a dual-role admin optometrist', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => 'Dual',
            'last_name' => 'Role',
            'email' => 'dualrole@example.com',
            'phone' => '9171234569',
            'roles' => [Role::Admin, Role::Optometrist],
            'password' => 'password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'dualrole@example.com')->firstOrFail();
    expect($user->isAdmin())->toBeTrue()
        ->and($user->isOptometrist())->toBeTrue();
});

test('create form requires name, phone, roles, and password', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => null,
            'last_name' => null,
            'phone' => null,
            'roles' => null,
            'password' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['first_name', 'last_name', 'phone', 'roles', 'password']);
});

// ─── Edit ─────────────────────────────────────────────────────────────────────

test('admin can edit a user name and role', function () {
    $user = User::factory()->staff()->create(['phone' => '+639171111111']);

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm([
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'roles' => [Role::Admin],
            'phone' => '9171111111',
            'password' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($user->fresh()->first_name)->toBe('Updated')
        ->and($user->fresh()->last_name)->toBe('Name')
        ->and($user->fresh()->isAdmin())->toBeTrue();
});

test('admin can assign dual-role to existing user', function () {
    $user = User::factory()->staff()->create(['phone' => '+639171111112']);

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm([
            'phone' => '9171111112',
            'password' => null,
            'roles' => [Role::Admin, Role::Optometrist],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->isAdmin())->toBeTrue()
        ->and($user->fresh()->isOptometrist())->toBeTrue();
});

test('optometrist scope excludes inactive users', function () {
    $optometrist = User::factory()->optometrist()->create();
    $inactiveOptometrist = User::factory()->optometrist()->create(['is_active' => false]);

    expect(User::query()->optometrists()->pluck('id')->all())
        ->toContain($optometrist->id)
        ->not->toContain($inactiveOptometrist->id);
});

test('password is not changed when left blank on edit', function () {
    $user = User::factory()->staff()->create(['phone' => '+639172222222']);
    $originalHash = $user->password;

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['phone' => '9172222222', 'password' => '', 'roles' => [Role::Staff]])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($user->fresh()->password)->toBe($originalHash);
});

test('password is updated when provided on edit', function () {
    $user = User::factory()->staff()->create(['phone' => '+639173333333']);
    $originalHash = $user->password;

    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['phone' => '9173333333', 'password' => 'newpassword123', 'roles' => [Role::Staff]])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($user->fresh()->password)->not->toBe($originalHash);
});

test('created user password can authenticate', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => 'Login',
            'last_name' => 'Test User',
            'email' => 'logintest@example.com',
            'phone' => '9179999999',
            'roles' => [Role::Staff],
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
