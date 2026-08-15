<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patient can list notifications', function () {
    $patient = User::factory()->patient()->create();

    Notification::make()
        ->title('Test Notification')
        ->body('Test body')
        ->sendToDatabase($patient);

    $this->actingAs($patient)
        ->getJson('/api/v1/notifications')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data');
});

test('patient can get unread count', function () {
    $patient = User::factory()->patient()->create();

    Notification::make()
        ->title('Unread')
        ->sendToDatabase($patient);

    $this->actingAs($patient)
        ->getJson('/api/v1/notifications/unread-count')
        ->assertSuccessful()
        ->assertJson(['unread_count' => 1]);
});

test('patient can mark a notification as read', function () {
    $patient = User::factory()->patient()->create();

    Notification::make()
        ->title('To Read')
        ->sendToDatabase($patient);

    $notification = $patient->notifications()->first();

    $this->actingAs($patient)
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertSuccessful();

    expect($patient->fresh()->unreadNotifications)->toHaveCount(0);
});

test('patient can mark all notifications as read', function () {
    $patient = User::factory()->patient()->create();

    Notification::make()->title('One')->sendToDatabase($patient);
    Notification::make()->title('Two')->sendToDatabase($patient);

    $this->actingAs($patient)
        ->patchJson('/api/v1/notifications/read-all')
        ->assertSuccessful();

    expect($patient->fresh()->unreadNotifications)->toHaveCount(0);
});

test('patient cannot mark another users notification as read', function () {
    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();

    Notification::make()
        ->title('Other User')
        ->sendToDatabase($patient2);

    $notification = $patient2->notifications()->first();

    $this->actingAs($patient1)
        ->patchJson("/api/v1/notifications/{$notification->id}/read")
        ->assertForbidden();
});
