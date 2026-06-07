<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('roles are seeded idempotently', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Role::query()->pluck('name')->all())
        ->toEqualCanonicalizing(['admin', 'staff', 'customer'])
        ->and(Role::query()->count())->toBe(3);
});

test('users belong to one typed role', function () {
    $user = User::factory()->customer()->create();

    expect($user->role())->toBeInstanceOf(BelongsTo::class)
        ->and($user->role)->toBeInstanceOf(Role::class)
        ->and($user->role->name)->toBe('customer')
        ->and((new Role)->users())->toBeInstanceOf(HasMany::class);
});

test('user factories can create users for each fixed role', function (string $factoryState, string $roleName) {
    $user = User::factory()->{$factoryState}()->create();

    expect($user->role->name)->toBe($roleName);
})->with([
    'admin' => ['admin', 'admin'],
    'staff' => ['staff', 'staff'],
    'customer' => ['customer', 'customer'],
]);
