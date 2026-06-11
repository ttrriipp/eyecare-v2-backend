<?php

use App\Models\LensType;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('demo admin account is created with correct role', function () {
    $this->seed(DemoUserSeeder::class);

    $user = User::query()->where('email', 'admin@eyecare.test')->firstOrFail();

    expect($user->role->name)->toBe('admin')
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

test('demo staff account is created with correct role', function () {
    $this->seed(DemoUserSeeder::class);

    $user = User::query()->where('email', 'staff@eyecare.test')->firstOrFail();

    expect($user->role->name)->toBe('staff')
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

test('demo customer account is created with correct role', function () {
    $this->seed(DemoUserSeeder::class);

    $user = User::query()->where('email', 'customer@eyecare.test')->firstOrFail();

    expect($user->role->name)->toBe('customer')
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

test('demo user seeder is idempotent', function () {
    $this->seed(DemoUserSeeder::class);
    $this->seed(DemoUserSeeder::class);

    expect(User::query()->where('email', 'admin@eyecare.test')->count())->toBe(1)
        ->and(User::query()->where('email', 'staff@eyecare.test')->count())->toBe(1)
        ->and(User::query()->where('email', 'customer@eyecare.test')->count())->toBe(1);
});

test('catalog seed creates demo products and lens types', function () {
    $this->seed(CatalogSeeder::class);

    expect(Product::query()->where('is_active', true)->count())->toBeGreaterThanOrEqual(2)
        ->and(LensType::query()->count())->toBeGreaterThanOrEqual(3);
});

test('demo admin can access filament panel', function () {
    $this->seed(DemoUserSeeder::class);

    $admin = User::query()->where('email', 'admin@eyecare.test')->firstOrFail();

    expect($admin->canAccessPanel(app(Panel::class)->id('admin')))->toBeTrue();
});
