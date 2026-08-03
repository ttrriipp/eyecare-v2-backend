<?php

use App\Actions\BillingRecords\ResolveOpenCheckoutBillingRecord;
use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->resolve = app(ResolveOpenCheckoutBillingRecord::class);
    $this->patient = Patient::factory()->create();
});

test('creates new billing record when none exists', function () {
    $jobOrder = JobOrder::factory()->create(['patient_id' => $this->patient->id]);

    $billing = $this->resolve->handle($this->patient, jobOrder: $jobOrder);

    expect($billing->patient_id)->toBe($this->patient->id)
        ->and($billing->job_order_id)->toBe($jobOrder->id)
        ->and($billing->status)->toBe(BillingRecordStatus::Unpaid);
});

test('reuses existing unpaid record without posted payments', function () {
    $jobOrder = JobOrder::factory()->create(['patient_id' => $this->patient->id]);

    $existing = BillingRecord::factory()->create([
        'patient_id' => $this->patient->id,
        'job_order_id' => $jobOrder->id,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    $result = $this->resolve->handle($this->patient, jobOrder: $jobOrder);

    expect($result->id)->toBe($existing->id);
});

test('does not reuse record with posted payments', function () {
    $jobOrder = JobOrder::factory()->create(['patient_id' => $this->patient->id]);

    $existing = BillingRecord::factory()->create([
        'patient_id' => $this->patient->id,
        'job_order_id' => $jobOrder->id,
        'status' => BillingRecordStatus::PartiallyPaid,
        'amount_paid' => 3000,
        'balance_due' => 2000,
    ]);

    // Add a posted payment
    $existing->payments()->create([
        'amount' => 3000,
        'payment_method' => 'cash',
        'status' => 'posted',
        'recorded_by' => User::factory()->create()->id,
        'recorded_at' => now(),
    ]);

    $result = $this->resolve->handle($this->patient, jobOrder: $jobOrder);

    expect($result->id)->not->toBe($existing->id);
});

test('does not reuse voided record', function () {
    $jobOrder = JobOrder::factory()->create(['patient_id' => $this->patient->id]);

    BillingRecord::factory()->voided()->create([
        'patient_id' => $this->patient->id,
        'job_order_id' => $jobOrder->id,
    ]);

    $result = $this->resolve->handle($this->patient, jobOrder: $jobOrder);

    expect($result->status)->toBe(BillingRecordStatus::Unpaid);
});

test('encounter-only creates correct source context', function () {
    $encounter = Encounter::factory()->create(['patient_id' => $this->patient->id]);

    $billing = $this->resolve->handle($this->patient, encounter: $encounter);

    expect($billing->job_order_id)->toBeNull()
        ->and($billing->encounter_id)->toBe($encounter->id)
        ->and($billing->isEncounterOnly())->toBeTrue();
});

test('combined creates correct source context', function () {
    $jobOrder = JobOrder::factory()->create(['patient_id' => $this->patient->id]);
    $encounter = Encounter::factory()->create(['patient_id' => $this->patient->id]);

    $billing = $this->resolve->handle($this->patient, jobOrder: $jobOrder, encounter: $encounter);

    expect($billing->job_order_id)->toBe($jobOrder->id)
        ->and($billing->encounter_id)->toBe($encounter->id)
        ->and($billing->isCombined())->toBeTrue();
});
