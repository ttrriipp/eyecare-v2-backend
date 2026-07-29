<?php

use App\Enums\EyewearProgress;
use App\Enums\QuotationStatus;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Services\Eyewear\BuildEyewearAggregate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('presented estimate maps to estimate_available', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Presented,
    ]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateAvailable)
        ->and($aggregate->key)->toBe($quotation->eyewear_key)
        ->and($aggregate->totalAmount)->toBe('5000.00')
        ->and($aggregate->preparation)->toBeNull()
        ->and($aggregate->dispensing)->toBeNull()
        ->and($aggregate->paymentSummary)->toBeNull();
});

test('accepted estimate maps to estimate_available', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
    ]);
    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 8000,
        'discount_amount' => 500,
        'total' => 7500,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateAvailable)
        ->and($aggregate->totalAmount)->toBe('7500.00');
});

test('declined estimate maps to estimate_declined', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateDeclined);
});

test('expired estimate maps to estimate_expired', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Expired]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateExpired);
});

test('draft estimate cannot produce an aggregate', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    app(BuildEyewearAggregate::class)->handle($quotation, null);
})->throws(InvalidArgumentException::class);

test('estimate section includes items from latest revision', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 8500,
        'discount_amount' => 500,
        'total' => 8000,
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Classic Rectangle Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Single Vision Lens',
        'quantity' => 1,
        'unit_price' => 4000,
        'amount' => 4000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->estimate)->not->toBeNull()
        ->and($aggregate->estimate['quotation_number'])->toBe($quotation->quotation_number)
        ->and($aggregate->estimate['status'])->toBe('presented')
        ->and($aggregate->estimate['subtotal'])->toBe('8500.00')
        ->and($aggregate->estimate['discount_amount'])->toBe('500.00')
        ->and($aggregate->estimate['total'])->toBe('8000.00')
        ->and($aggregate->estimate['items'])->toHaveCount(2)
        ->and($aggregate->estimate['items'][0]['description'])->toBe('Classic Rectangle Frame')
        ->and($aggregate->estimate['items'][0]['unit_price'])->toBe('4500.00');
});

test('money values are exact two-decimal strings', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 1000.5,
        'total' => 1000.5,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->totalAmount)->toBe('1000.50')
        ->and($aggregate->estimate['subtotal'])->toBe('1000.50')
        ->and($aggregate->estimate['total'])->toBe('1000.50');
});

test('description uses first item with count for multiple items', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    $revision = QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Classic Rectangle Frame',
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Single Vision Lens',
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->description)->toBe('Classic Rectangle Frame + 1 more');
});

test('description falls back to Eyewear transaction when no items', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->description)->toBe('Eyewear transaction');
});

test('consultation timestamp resolves from encounter appointment', function () {
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'scheduled_at' => '2026-07-27T09:00:00+08:00',
    ]);
    $encounter->update(['appointment_id' => $appointment->id]);

    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Presented,
    ]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->consultationAt)->toBe('2026-07-27T09:00:00+08:00');
});

test('consultation timestamp is null when no encounter link', function () {
    $quotation = Quotation::factory()->create([
        'encounter_id' => null,
        'status' => QuotationStatus::Presented,
    ]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->consultationAt)->toBeNull();
});
