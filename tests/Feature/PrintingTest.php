<?php

use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('billing pdf route is absent', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/pdf/billings/1')
        ->assertNotFound();
});

test('billing thermal route is absent', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/thermal/billings/1')
        ->assertNotFound();
});

test('prescription pdf uses patient relationship', function () {
    $prescription = Prescription::factory()->create();

    expect($prescription->patient)->not->toBeNull()
        ->and($prescription->patient->full_name)->not->toBeEmpty();
});

test('prescription print route requires authentication', function () {
    $prescription = Prescription::factory()->create();

    $this->get("/pdf/prescriptions/{$prescription->id}")
        ->assertRedirect();
});
