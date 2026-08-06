<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->admin()->create();
});

// ─── Deactivation ───────────────────────────────────────────────────────────

test('a deactivated user cannot access the admin panel', function () {
    $staff = User::factory()->staff()->create(['is_active' => false]);

    expect($staff->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();
});

test('a deactivated optometrist is excluded from the optometrists scope', function () {
    $optometrist = User::factory()->optometrist()->create(['is_active' => false]);

    expect(User::query()->optometrists()->whereKey($optometrist->id)->exists())->toBeFalse();
});

test('an admin can deactivate a staff account from the table', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('toggleActive')->table($staff))
        ->assertNotified();

    expect($staff->fresh()->is_active)->toBeFalse();
});

test('an admin can reactivate a deactivated staff account', function () {
    $staff = User::factory()->staff()->create(['is_active' => false]);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('toggleActive')->table($staff))
        ->assertNotified();

    expect($staff->fresh()->is_active)->toBeTrue();
});

test('the last active admin cannot be deactivated', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('toggleActive')->table($this->admin))
        ->assertNotified();

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

test('a second admin can still be deactivated', function () {
    $secondAdmin = User::factory()->admin()->create();

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('toggleActive')->table($secondAdmin))
        ->assertNotified();

    expect($secondAdmin->fresh()->is_active)->toBeFalse();
});

// ─── Forced first-login password change ──────────────────────────────────────

test('a newly created user is forced to change their password before reaching anything else', function () {
    $staffRole = Role::where('name', 'staff')->firstOrFail();

    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'first_name' => 'New',
            'last_name' => 'Staff Member',
            'email' => 'newstaff@example.com',
            'phone' => '9171234567',
            'role_id' => $staffRole->id,
            'password' => 'password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $newStaff = User::where('email', 'newstaff@example.com')->firstOrFail();

    expect($newStaff->must_change_password)->toBeTrue();

    $this->actingAs($newStaff)
        ->get('/admin')
        ->assertRedirect('/admin/profile');
});

test('the redirect clears once the user changes their own password', function () {
    $staff = User::factory()->staff()->create([
        'password' => bcrypt('temp-password'),
        'must_change_password' => true,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'email' => $staff->email,
            'password' => 'chosen-password',
            'passwordConfirmation' => 'chosen-password',
            'currentPassword' => 'temp-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($staff->fresh()->must_change_password)->toBeFalse()
        ->and($staff->fresh()->password_changed_at)->not->toBeNull();

    $this->get('/admin')->assertSuccessful();
});

test('a user pending password change can still reach the profile page and log out', function () {
    $staff = User::factory()->staff()->create(['must_change_password' => true]);

    $this->actingAs($staff)
        ->get('/admin/profile')
        ->assertSuccessful();

    $this->actingAs($staff)
        ->post('/admin/logout')
        ->assertRedirect();
});
