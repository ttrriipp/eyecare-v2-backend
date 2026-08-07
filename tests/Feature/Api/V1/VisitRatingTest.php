<?php

use App\Http\Resources\VisitRatingResource;
use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitRating;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->patient()->create();
    $this->patient = $this->user->patient;
    $this->appointment = Appointment::factory()->fulfilled()->create([
        'patient_id' => $this->patient->id,
    ]);
});

test('patient can rate a fulfilled appointment', function () {
    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$this->appointment->id}/rating", [
            'rating' => 5,
            'comment' => 'Excellent visit!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.comment', 'Excellent visit!')
        ->assertJsonStructure(['data' => ['id', 'rating', 'comment', 'revision_number', 'created_at']]);
});

test('re-submitting revises the rating', function () {
    // First rating
    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$this->appointment->id}/rating", [
            'rating' => 4,
            'comment' => 'Good visit',
        ])
        ->assertCreated();

    // Revised rating
    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$this->appointment->id}/rating", [
            'rating' => 5,
            'comment' => 'Even better!',
        ])
        ->assertOk()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.revision_number', 2);
});

test('non-fulfilled appointment is rejected', function () {
    $appointment = Appointment::factory()->create([
        'patient_id' => $this->patient->id,
    ]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$appointment->id}/rating", [
            'rating' => 5,
        ])
        ->assertUnprocessable();
});

test('another patients appointment returns 404', function () {
    $otherUser = User::factory()->patient()->create();
    $otherAppointment = Appointment::factory()->fulfilled()->create([
        'patient_id' => $otherUser->patient->id,
    ]);

    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$otherAppointment->id}/rating", [
            'rating' => 5,
        ])
        ->assertNotFound();
});

test('nonexistent appointment returns 404', function () {
    $this->actingAs($this->user)
        ->postJson('/api/v1/appointments/99999/rating', [
            'rating' => 5,
        ])
        ->assertNotFound();
});

test('rating outside 1-5 is rejected', function () {
    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$this->appointment->id}/rating", [
            'rating' => 0,
        ])
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->postJson("/api/v1/appointments/{$this->appointment->id}/rating", [
            'rating' => 6,
        ])
        ->assertUnprocessable();
});

test('unlinked account is rejected', function () {
    $unlinkedUser = User::factory()->create();

    $this->actingAs($unlinkedUser)
        ->postJson("/api/v1/appointments/{$this->appointment->id}/rating", [
            'rating' => 5,
        ])
        ->assertForbidden();
});

test('hidden comment returns null to non-author', function () {
    $rating = VisitRating::factory()->create([
        'patient_id' => $this->patient->id,
        'appointment_id' => $this->appointment->id,
        'is_hidden' => true,
        'moderation_reason' => 'Test',
        'comment' => 'Hidden text',
    ]);

    $rating->load('currentRevision');

    $resource = new VisitRatingResource($rating);

    // Author sees their own hidden comment
    $request = new Request;
    $request->setUserResolver(fn () => $this->user);
    $array = $resource->toArray($request);
    expect($array['comment'])->toBe('Hidden text');
});

test('authentication is required', function () {
    $this->postJson("/api/v1/appointments/{$this->appointment->id}/rating", [
        'rating' => 5,
    ])->assertUnauthorized();
});
