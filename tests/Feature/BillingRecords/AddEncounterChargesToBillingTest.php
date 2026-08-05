<?php

use App\Actions\BillingRecords\AddEncounterChargesToBilling;
use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
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
    $this->action = app(AddEncounterChargesToBilling::class);
});

test('creates encounter-only billing record with items', function () {
    $encounter = Encounter::factory()->inProgress()->create();

    $billing = $this->action->handle(
        encounter: $encounter,
        items: [
            ['description' => 'Eye Exam', 'quantity' => 1, 'unit_price' => 1500],
            ['description' => 'Tonometry', 'quantity' => 1, 'unit_price' => 500],
        ],
    );

    expect($billing->encounter_id)->toBe($encounter->id)
        ->and($billing->job_order_id)->toBeNull()
        ->and($billing->isEncounterOnly())->toBeTrue()
        ->and($billing->items)->toHaveCount(2)
        ->and((float) $billing->subtotal_amount)->toBe(2000.0);
});

test('reuses existing encounter-only billing record', function () {
    $encounter = Encounter::factory()->inProgress()->create();

    $first = $this->action->handle(
        encounter: $encounter,
        items: [['description' => 'Eye Exam', 'quantity' => 1, 'unit_price' => 1500]],
    );

    $second = $this->action->handle(
        encounter: $encounter,
        items: [['description' => 'Tonometry', 'quantity' => 1, 'unit_price' => 500]],
    );

    expect($first->id)->toBe($second->id)
        ->and($second->fresh()->items)->toHaveCount(2);
});

test('creates new billing after posted payment', function () {
    $encounter = Encounter::factory()->inProgress()->create();

    $first = $this->action->handle(
        encounter: $encounter,
        items: [['description' => 'Eye Exam', 'quantity' => 1, 'unit_price' => 1500]],
    );

    // Add a posted payment
    $first->payments()->create([
        'amount' => 1500,
        'payment_method' => 'cash',
        'status' => 'posted',
        'recorded_by' => $this->staff->id,
        'recorded_at' => now(),
    ]);

    $second = $this->action->handle(
        encounter: $encounter,
        items: [['description' => 'Follow-up', 'quantity' => 1, 'unit_price' => 500]],
    );

    expect($second->id)->not->toBe($first->id);
});

test('a charge line can reference the service catalog', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    $service = Service::factory()->create(['price' => 1500]);

    $billing = $this->action->handle(
        encounter: $encounter,
        items: [['description' => $service->name, 'quantity' => 1, 'unit_price' => 1500, 'service_id' => $service->id]],
    );

    expect($billing->items->first()->service_id)->toBe($service->id);
});

test('an inactive service cannot be charged', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    $service = Service::factory()->inactive()->create();

    $this->action->handle(
        encounter: $encounter,
        items: [['description' => $service->name, 'quantity' => 1, 'unit_price' => 500, 'service_id' => $service->id]],
    );
})->throws(ValidationException::class);

test('rejects empty items', function () {
    $encounter = Encounter::factory()->inProgress()->create();

    $this->action->handle(
        encounter: $encounter,
        items: [],
    );
})->throws(ValidationException::class, 'At least one service line');

test('reuses a combined billing record created from a confirmed quotation', function () {
    $encounter = Encounter::factory()->inProgress()->create();

    // Simulates the record ConfirmQuotationSale would have created for this encounter.
    $existing = BillingRecord::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'job_order_id' => JobOrder::factory()->create(['patient_id' => $encounter->patient_id])->id,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    $billing = $this->action->handle(
        encounter: $encounter,
        items: [['description' => 'Follow-up Consultation', 'quantity' => 1, 'unit_price' => 500]],
    );

    expect($billing->id)->toBe($existing->id)
        ->and($billing->items)->toHaveCount(1);
});

test('creates separate billing when previous has posted payments', function () {
    $encounter = Encounter::factory()->inProgress()->create();

    // Create an initial billing record with a posted payment
    $billing = BillingRecord::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'job_order_id' => null,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    $billing->payments()->create([
        'amount' => 1500,
        'payment_method' => 'cash',
        'status' => 'posted',
        'recorded_by' => $this->staff->id,
        'recorded_at' => now(),
    ]);

    // Add more charges - creates a new billing record
    $newBilling = $this->action->handle(
        encounter: $encounter,
        items: [['description' => 'Follow-up', 'quantity' => 1, 'unit_price' => 500]],
    );

    expect($newBilling->id)->not->toBe($billing->id)
        ->and($newBilling->items)->toHaveCount(1);
});
