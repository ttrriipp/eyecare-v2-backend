<?php

use App\Actions\BillingRecords\AddDirectServiceChargesToBilling;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
    $this->action = app(AddDirectServiceChargesToBilling::class);
});

test('creates a direct billing record with no encounter or job order source', function () {
    $patient = Patient::factory()->create();

    $billing = $this->action->handle(
        patient: $patient,
        items: [['description' => 'Frame repair', 'quantity' => 1, 'unit_price' => 300]],
    );

    expect($billing->patient_id)->toBe($patient->id)
        ->and($billing->encounter_id)->toBeNull()
        ->and($billing->job_order_id)->toBeNull()
        ->and($billing->items)->toHaveCount(1)
        ->and((float) $billing->subtotal_amount)->toBe(300.0);
});

test('rejects empty items', function () {
    $patient = Patient::factory()->create();

    $this->action->handle(patient: $patient, items: []);
})->throws(ValidationException::class, 'At least one service line');

test('a charge line can reference the service catalog', function () {
    $patient = Patient::factory()->create();
    $service = Service::factory()->create(['price' => 500]);

    $billing = $this->action->handle(
        patient: $patient,
        items: [['description' => $service->name, 'quantity' => 1, 'unit_price' => 500, 'service_id' => $service->id]],
    );

    expect($billing->items->first()->service_id)->toBe($service->id);
});

test('an inactive service cannot be charged', function () {
    $patient = Patient::factory()->create();
    $service = Service::factory()->inactive()->create();

    $this->action->handle(
        patient: $patient,
        items: [['description' => $service->name, 'quantity' => 1, 'unit_price' => 500, 'service_id' => $service->id]],
    );
})->throws(ValidationException::class);
