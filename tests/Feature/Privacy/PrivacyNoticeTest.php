<?php

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('user model has privacy acknowledgement fields', function () {
    $user = User::factory()->patient()->create([
        'privacy_notice_version' => '1.0',
        'privacy_acknowledged_at' => now(),
    ]);

    expect($user->privacy_notice_version)->toBe('1.0')
        ->and($user->privacy_acknowledged_at)->toBeInstanceOf(Carbon::class);
});

test('privacy acknowledgement fields are nullable', function () {
    $user = User::factory()->patient()->create();

    expect($user->privacy_notice_version)->toBeNull()
        ->and($user->privacy_acknowledged_at)->toBeNull();
});

test('privacy acknowledgement can be recorded via update', function () {
    $user = User::factory()->patient()->create();

    $user->update([
        'privacy_notice_version' => '2.0',
        'privacy_acknowledged_at' => now(),
    ]);

    $user->refresh();

    expect($user->privacy_notice_version)->toBe('2.0')
        ->and($user->privacy_acknowledged_at)->not->toBeNull();
});
