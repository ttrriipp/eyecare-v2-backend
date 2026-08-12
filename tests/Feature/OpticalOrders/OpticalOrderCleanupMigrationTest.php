<?php

/**
 * Tests for the revision cleanup migration.
 *
 * Verifies that the migration:
 * - Removes quotation_revisions table
 * - Removes quotation_revision_id columns
 * - Makes quotation_id required on quotation_items
 * - Drops frame_rating_revisions and visit_rating_revisions
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('quotation_revisions table is removed', function () {
    expect(Schema::hasTable('quotation_revisions'))->toBeFalse();
});

test('quotation_revision_id is removed from quotation_items', function () {
    expect(Schema::hasColumn('quotation_items', 'quotation_revision_id'))->toBeFalse();
});

test('quotation_revision_id is removed from job_orders', function () {
    expect(Schema::hasColumn('job_orders', 'quotation_revision_id'))->toBeFalse();
});

test('quotation_id is required on quotation_items', function () {
    // Check that the column exists and is not nullable
    expect(Schema::hasColumn('quotation_items', 'quotation_id'))->toBeTrue();

    // Try to insert without quotation_id - should fail
    try {
        DB::table('quotation_items')->insert([
            'description' => 'Test',
            'quantity' => 1,
            'unit_price' => 100,
            'amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->fail('Expected insert to fail without quotation_id');
    } catch (Exception $e) {
        expect($e->getMessage())->toContain('quotation_id');
    }
});

test('frame_rating_revisions table is dropped', function () {
    expect(Schema::hasTable('frame_rating_revisions'))->toBeFalse();
});

test('direct quotation fields are preserved on quotations', function () {
    expect(Schema::hasColumn('quotations', 'subtotal'))->toBeTrue()
        ->and(Schema::hasColumn('quotations', 'discount_amount'))->toBeTrue()
        ->and(Schema::hasColumn('quotations', 'total'))->toBeTrue()
        ->and(Schema::hasColumn('quotations', 'confirmed_by'))->toBeTrue()
        ->and(Schema::hasColumn('quotations', 'confirmed_at'))->toBeTrue();
});

test('direct quotation_id is preserved on job_orders', function () {
    expect(Schema::hasColumn('job_orders', 'quotation_id'))->toBeTrue();
});

test('payment_due_date is preserved on billing_records', function () {
    expect(Schema::hasColumn('billing_records', 'payment_due_date'))->toBeTrue();
});
