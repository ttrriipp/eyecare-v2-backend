<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('profile page renders for a staff user', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)
        ->get('/admin/profile')
        ->assertSuccessful();
});

test('a guest is redirected away from the profile page', function () {
    $this->get('/admin/profile')
        ->assertRedirect('/admin/login');
});

test('a staff member can change their own password and re-authenticate with it', function () {
    $staff = User::factory()->staff()->create([
        'password' => bcrypt('old-password'),
    ]);

    $this->actingAs($staff);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'email' => $staff->email,
            'password' => 'new-password',
            'passwordConfirmation' => 'new-password',
            'currentPassword' => 'old-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('new-password', $staff->fresh()->password))->toBeTrue();
});

test('changing the password requires the current password', function () {
    $staff = User::factory()->staff()->create([
        'password' => bcrypt('old-password'),
    ]);

    $this->actingAs($staff);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'email' => $staff->email,
            'password' => 'new-password',
            'passwordConfirmation' => 'new-password',
            'currentPassword' => 'wrong-current-password',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect(Hash::check('old-password', $staff->fresh()->password))->toBeTrue();
});

test('a staff member can update their own name via the profile page', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => $staff->email,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->fresh()->first_name)->toBe('Updated')
        ->and($staff->fresh()->last_name)->toBe('Name');
});

test('the password reset request page renders', function () {
    $this->get('/admin/password-reset/request')
        ->assertSuccessful();
});
