<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Services\SingleSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['auth.single_session_enabled' => true]);
});

test('the combined admin optometrist role can claim only one active session', function () {
    $user = User::factory()->adminOptometrist()->create();
    $manager = app(SingleSessionManager::class);

    expect($user->requiresSingleSession())->toBeTrue()
        ->and($manager->claim($user, 'first-session'))->toBeTrue()
        ->and($manager->claim($user, 'second-session'))->toBeFalse();
});

test('single-session enforcement can be temporarily disabled', function () {
    config(['auth.single_session_enabled' => false]);

    $user = User::factory()->adminOptometrist()->create();
    $manager = app(SingleSessionManager::class);

    expect($user->requiresSingleSession())->toBeFalse()
        ->and($manager->claim($user, 'first-session'))->toBeTrue()
        ->and($manager->claim($user, 'second-session'))->toBeTrue();
});

test('admin-only and optometrist-only roles can use multiple sessions', function () {
    $manager = app(SingleSessionManager::class);
    $admin = User::factory()->admin()->create();
    $optometrist = User::factory()->optometrist()->create();

    expect($admin->requiresSingleSession())->toBeFalse()
        ->and($manager->claim($admin, 'admin-first-session'))->toBeTrue()
        ->and($manager->claim($admin, 'admin-second-session'))->toBeTrue()
        ->and($optometrist->requiresSingleSession())->toBeFalse()
        ->and($manager->claim($optometrist, 'optometrist-first-session'))->toBeTrue()
        ->and($manager->claim($optometrist, 'optometrist-second-session'))->toBeTrue();
});

test('a stale combined-role session claim can be replaced', function () {
    $user = User::factory()->adminOptometrist()->create([
        'active_session_hash' => hash('sha256', 'stale-session'),
        'active_session_last_seen_at' => now()->subMinutes((int) config('session.lifetime') + 1),
    ]);

    expect(app(SingleSessionManager::class)->claim($user, 'new-session'))->toBeTrue()
        ->and($user->fresh()->active_session_hash)->toBe(hash('sha256', 'new-session'));
});

test('a combined-role account releases its claim only for the matching session', function () {
    $user = User::factory()->adminOptometrist()->create();
    $manager = app(SingleSessionManager::class);

    $manager->claim($user, 'active-session');
    $manager->release($user, 'different-session');

    expect($user->fresh()->active_session_hash)->toBe(hash('sha256', 'active-session'));

    $manager->release($user, 'active-session');

    expect($user->fresh()->active_session_hash)->toBeNull();
});

test('a combined-role account records its active session after login', function () {
    $user = User::factory()->adminOptometrist()->create([
        'email' => 'owner@eyecare.test',
        'password' => bcrypt('password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertRedirect('/admin');

    expect($user->fresh()->active_session_hash)->not->toBeNull()
        ->and($user->fresh()->active_session_last_seen_at)->not->toBeNull();
});

test('a combined-role account rejects a login while another session is active', function () {
    $user = User::factory()->adminOptometrist()->create([
        'email' => 'owner@eyecare.test',
        'password' => bcrypt('password'),
        'active_session_hash' => hash('sha256', 'existing-session'),
        'active_session_last_seen_at' => now(),
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    expect($user->fresh()->active_session_hash)->toBe(hash('sha256', 'existing-session'));
});

test('a competing combined-role panel session is logged out', function () {
    $user = User::factory()->adminOptometrist()->create();

    $this->actingAs($user);

    $user->forceFill([
        'active_session_hash' => hash('sha256', 'other-session'),
        'active_session_last_seen_at' => now(),
    ])->saveQuietly();

    $this->get('/admin')
        ->assertRedirect('/admin/login')
        ->assertSessionHas('error', 'Your session ended because this account is active in another browser or device.');

    expect($user->fresh()->active_session_hash)->toBe(hash('sha256', 'other-session'));
});
