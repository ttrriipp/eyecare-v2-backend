<?php

use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('conversation api response uses patient_id not customer_id', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create(['patient_id' => $patient->patient->id]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversations')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_id', $patient->patient->id)
        ->assertJsonMissing(['customer_id']);
});

test('patient can access own conversation', function () {
    $patient = User::factory()->patient()->create();
    $conversation = Conversation::query()->create(['patient_id' => $patient->patient->id]);

    $this->actingAs($patient)
        ->getJson('/api/v1/conversations')
        ->assertSuccessful();
});

test('patient cannot access another patients conversation messages', function () {
    $patient1 = User::factory()->patient()->create();
    $patient2 = User::factory()->patient()->create();
    $conversation = Conversation::query()->create(['patient_id' => $patient2->patient->id]);

    $this->actingAs($patient1)
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")
        ->assertNotFound();
});
