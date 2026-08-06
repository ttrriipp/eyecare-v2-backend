<?php

use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a successful login writes an audit entry and records an IP address', function () {
    $staff = User::factory()->staff()->create([
        'password' => bcrypt('password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $staff->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertRedirect('/admin');

    $entry = AuditLog::query()->where('action', 'user.logged_in')->where('subject_id', $staff->id)->firstOrFail();

    expect($entry->actor_id)->toBe($staff->id)
        ->and($entry->ip_address)->not->toBeNull();

    expect($staff->fresh()->last_login_at)->not->toBeNull();
});

test('logging out writes an audit entry', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)->post('/admin/logout');

    expect(AuditLog::query()->where('action', 'user.logged_out')->where('subject_id', $staff->id)->exists())->toBeTrue();
});

test('a wrong password for a known account writes a failed-login audit entry', function () {
    $staff = User::factory()->staff()->create([
        'password' => bcrypt('password'),
    ]);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $staff->email,
            'password' => 'wrong-password',
        ])
        ->call('authenticate');

    expect(AuditLog::query()->where('action', 'user.login_failed')->where('subject_id', $staff->id)->exists())->toBeTrue();
});

test('an unknown email does not write any audit entry', function () {
    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'nobody@example.com',
            'password' => 'whatever-password',
        ])
        ->call('authenticate');

    expect(AuditLog::query()->whereIn('action', ['user.login_failed', 'user.logged_in'])->exists())->toBeFalse();
});
