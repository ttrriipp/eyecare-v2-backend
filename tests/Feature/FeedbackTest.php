<?php

use App\Models\Feedback;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('feedback resource does not expose order_id', function () {
    $patient = User::factory()->patient()->create();
    $feedback = Feedback::factory()->create(['patient_id' => $patient->patient->id]);

    $this->actingAs($patient)
        ->getJson("/api/v1/feedback/{$feedback->id}")
        ->assertSuccessful()
        ->assertJsonMissing(['order_id']);
});

test('feedback is patient-owned', function () {
    $patient = User::factory()->patient()->create();
    $feedback = Feedback::factory()->create(['patient_id' => $patient->patient->id]);

    expect($feedback->patient)->not->toBeNull()
        ->and($feedback->patient->id)->toBe($patient->patient->id);
});

test('feedback model has no order relationship', function () {
    $feedback = new Feedback;

    expect(method_exists($feedback, 'order'))->toBeFalse();
});
