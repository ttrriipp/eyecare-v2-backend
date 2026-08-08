<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('roles are seeded idempotently', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Role::query()->pluck('name')->all())
        ->toEqualCanonicalizing(['admin', 'optometrist', 'staff', 'patient'])
        ->and(Role::query()->count())->toBe(4);
});

test('role model defines the four fixed role name constants', function () {
    expect(Role::Admin)->toBe('admin')
        ->and(Role::Optometrist)->toBe('optometrist')
        ->and(Role::Staff)->toBe('staff')
        ->and(Role::Patient)->toBe('patient');
});

test('users have a many-to-many roles relationship', function () {
    $user = User::factory()->patient()->create();

    expect($user->roles())->toBeInstanceOf(BelongsToMany::class)
        ->and($user->roles)->toHaveCount(1)
        ->and($user->roles->first()->name)->toBe('patient');
});

test('user factories can create users for each fixed role', function (string $factoryState, array $expectedRoles) {
    $user = User::factory()->{$factoryState}()->create();

    expect($user->roles->pluck('name')->sort()->values()->all())
        ->toBe(collect($expectedRoles)->sort()->values()->all());
})->with([
    'admin' => ['admin', ['admin']],
    'optometrist' => ['optometrist', ['optometrist']],
    'staff' => ['staff', ['staff']],
    'patient' => ['patient', ['patient']],
    'admin+optometrist' => ['adminOptometrist', ['admin', 'optometrist']],
]);

test('hasRole checks for a specific role', function () {
    $user = User::factory()->admin()->create();

    expect($user->hasRole('admin'))->toBeTrue()
        ->and($user->hasRole('staff'))->toBeFalse()
        ->and($user->hasRole('patient'))->toBeFalse();
});

test('isAdmin returns true only for admin role', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();

    expect($admin->isAdmin())->toBeTrue()
        ->and($staff->isAdmin())->toBeFalse();
});

test('isOptometrist returns true only for optometrist role', function () {
    $optometrist = User::factory()->optometrist()->create();
    $staff = User::factory()->staff()->create();

    expect($optometrist->isOptometrist())->toBeTrue()
        ->and($staff->isOptometrist())->toBeFalse();
});

test('isStaff returns true only for staff role', function () {
    $staff = User::factory()->staff()->create();
    $admin = User::factory()->admin()->create();

    expect($staff->isStaff())->toBeTrue()
        ->and($admin->isStaff())->toBeFalse();
});

test('isPatient returns true only for patient role', function () {
    $patient = User::factory()->patient()->create();
    $admin = User::factory()->admin()->create();

    expect($patient->isPatient())->toBeTrue()
        ->and($admin->isPatient())->toBeFalse();
});

test('hasPanelRole returns true for admin, optometrist, and staff', function () {
    $admin = User::factory()->admin()->create();
    $optometrist = User::factory()->optometrist()->create();
    $staff = User::factory()->staff()->create();
    $patient = User::factory()->patient()->create();

    expect($admin->hasPanelRole())->toBeTrue()
        ->and($optometrist->hasPanelRole())->toBeTrue()
        ->and($staff->hasPanelRole())->toBeTrue()
        ->and($patient->hasPanelRole())->toBeFalse();
});

test('canAccessPanel allows panel roles when active', function () {
    $panel = filament()->getPanel('admin');
    $admin = User::factory()->admin()->create();
    $optometrist = User::factory()->optometrist()->create();
    $staff = User::factory()->staff()->create();
    $patient = User::factory()->patient()->create();

    expect($admin->canAccessPanel($panel))->toBeTrue()
        ->and($optometrist->canAccessPanel($panel))->toBeTrue()
        ->and($staff->canAccessPanel($panel))->toBeTrue()
        ->and($patient->canAccessPanel($panel))->toBeFalse();
});

test('canAccessPanel denies inactive panel users', function () {
    $panel = filament()->getPanel('admin');
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect($admin->canAccessPanel($panel))->toBeFalse();
});

test('scopeOptometrists includes active optometrists', function () {
    $optometrist = User::factory()->optometrist()->create();
    $staff = User::factory()->staff()->create();

    $optometrists = User::optometrists()->pluck('id')->all();

    expect($optometrists)->toContain($optometrist->id)
        ->and($optometrists)->not->toContain($staff->id);
});

test('scopeOptometrists excludes inactive optometrists', function () {
    $optometrist = User::factory()->optometrist()->create(['is_active' => false]);

    expect(User::optometrists()->where('id', $optometrist->id)->exists())->toBeFalse();
});

test('scopePatients includes only patient role users', function () {
    $patient = User::factory()->patient()->create();
    $admin = User::factory()->admin()->create();

    $patients = User::patients()->pluck('id')->all();

    expect($patients)->toContain($patient->id)
        ->and($patients)->not->toContain($admin->id);
});

test('legacy customer factory state resolves to the patient role during migration', function () {
    $user = User::factory()->customer()->create();

    expect($user->roles->pluck('name')->all())->toBe(['patient'])
        ->and(Role::query()->where('name', 'customer')->doesntExist())->toBeTrue();
});

test('role seeding migrates the legacy customer role without losing users', function () {
    $legacyCustomerRole = Role::factory()->create(['name' => 'customer']);
    $user = User::factory()->create(['role_id' => $legacyCustomerRole->id]);

    // Simulate backfill for legacy user.
    DB::table('role_user')->insert([
        'role_id' => Role::query()->where('name', 'patient')->value('id'),
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(RoleSeeder::class);

    expect($user->fresh()->roles->pluck('name')->all())->toBe(['patient'])
        ->and(Role::query()->pluck('name')->all())
        ->toEqualCanonicalizing(['admin', 'optometrist', 'staff', 'patient'])
        ->and(Role::query()->count())->toBe(4);
});
