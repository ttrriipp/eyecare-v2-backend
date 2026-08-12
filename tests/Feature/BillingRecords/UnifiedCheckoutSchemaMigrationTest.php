<?php

/**
 * Tests for unified-checkout billing schema migration.
 */

use App\Models\BillingRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('billing_record_items table exists', function () {
    expect(Schema::hasTable('billing_record_items'))->toBeTrue();
});

test('billing_record_items has required columns', function () {
    expect(Schema::hasColumn('billing_record_items', 'id'))->toBeTrue()
        ->and(Schema::hasColumn('billing_record_items', 'billing_record_id'))->toBeTrue()
        ->and(Schema::hasColumn('billing_record_items', 'description'))->toBeTrue()
        ->and(Schema::hasColumn('billing_record_items', 'quantity'))->toBeTrue()
        ->and(Schema::hasColumn('billing_record_items', 'unit_price'))->toBeTrue()
        ->and(Schema::hasColumn('billing_record_items', 'amount'))->toBeTrue()
        ->and(Schema::hasColumn('billing_record_items', 'job_order_item_id'))->toBeTrue()
        ->and(Schema::hasColumn('billing_record_items', 'encounter_id'))->toBeTrue()
        ->and(Schema::hasColumn('billing_record_items', 'source_kind'))->toBeTrue();
});

test('billing_records has subtotal and discount columns', function () {
    expect(Schema::hasColumn('billing_records', 'subtotal_amount'))->toBeTrue()
        ->and(Schema::hasColumn('billing_records', 'discount_amount'))->toBeTrue();
});

test('billing_record_items can store itemized data', function () {
    $billing = BillingRecord::factory()->create();

    DB::table('billing_record_items')->insert([
        'billing_record_id' => $billing->id,
        'source_kind' => 'optical_order',
        'description' => 'Test Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $item = DB::table('billing_record_items')
        ->where('billing_record_id', $billing->id)
        ->first();

    expect($item)->not->toBeNull()
        ->and($item->source_kind)->toBe('optical_order')
        ->and($item->description)->toBe('Test Frame');
});
