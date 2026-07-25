<?php

use App\Models\Patient;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('quotation has a unique number', function () {
    $q1 = Quotation::factory()->create();
    $q2 = Quotation::factory()->create();

    expect($q1->quotation_number)->toStartWith('QUO-')
        ->and($q1->quotation_number)->not->toBe($q2->quotation_number);
});

test('quotation belongs to a patient', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create(['patient_id' => $patient->id]);

    expect($quotation->patient->id)->toBe($patient->id);
});

test('quotation has revisions', function () {
    $quotation = Quotation::factory()->create();
    $rev1 = QuotationRevision::factory()->create(['quotation_id' => $quotation->id, 'revision_number' => 1]);
    $rev2 = QuotationRevision::factory()->create(['quotation_id' => $quotation->id, 'revision_number' => 2]);

    expect($quotation->revisions)->toHaveCount(2);
});

test('revision snapshots items quantities prices and totals', function () {
    $quotation = Quotation::factory()->create();
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 5000,
        'discount_amount' => 500,
        'total' => 4500,
    ]);

    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Classic Frame — Matte Black',
        'quantity' => 1,
        'unit_price' => 2500,
        'amount' => 2500,
    ]);

    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Progressive Lens',
        'quantity' => 1,
        'unit_price' => 2500,
        'amount' => 2500,
    ]);

    $revision->load('items');

    expect((float) $revision->subtotal)->toBe(5000.0)
        ->and((float) $revision->discount_amount)->toBe(500.0)
        ->and((float) $revision->total)->toBe(4500.0)
        ->and($revision->items)->toHaveCount(2)
        ->and($revision->items->first()->description)->toBe('Classic Frame — Matte Black');
});

test('recalculating creates a revision instead of rewriting presented one', function () {
    $quotation = Quotation::factory()->create();
    $presented = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 5000,
        'total' => 5000,
        'presented_at' => now(),
    ]);

    // Create a new revision instead of editing the presented one
    $newRevision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 2,
        'subtotal' => 6000,
        'total' => 6000,
    ]);

    $quotation->load('revisions');

    expect($quotation->revisions)->toHaveCount(2)
        ->and((float) $presented->fresh()->subtotal)->toBe(5000.0) // Original unchanged
        ->and((float) $newRevision->subtotal)->toBe(6000.0);
});

test('revision recalculateTotals computes from items', function () {
    $revision = QuotationRevision::factory()->create([
        'discount_amount' => 200,
    ]);

    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'amount' => 1500,
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'amount' => 2000,
    ]);

    $revision->recalculateTotals();
    $revision->refresh();

    expect((float) $revision->subtotal)->toBe(3500.0)
        ->and((float) $revision->total)->toBe(3300.0); // 3500 - 200 discount
});

test('totals are deterministic from item amounts', function () {
    $revision = QuotationRevision::factory()->create(['discount_amount' => 0]);

    QuotationItem::factory()->create(['quotation_revision_id' => $revision->id, 'amount' => 100]);
    QuotationItem::factory()->create(['quotation_revision_id' => $revision->id, 'amount' => 200]);
    QuotationItem::factory()->create(['quotation_revision_id' => $revision->id, 'amount' => 300]);

    $revision->recalculateTotals();
    $revision->refresh();

    expect((float) $revision->subtotal)->toBe(600.0)
        ->and((float) $revision->total)->toBe(600.0);
});

test('quotation latestRevision returns highest revision number', function () {
    $quotation = Quotation::factory()->create();
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id, 'revision_number' => 1]);
    $latest = QuotationRevision::factory()->create(['quotation_id' => $quotation->id, 'revision_number' => 3]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id, 'revision_number' => 2]);

    expect($quotation->latestRevision->id)->toBe($latest->id);
});
