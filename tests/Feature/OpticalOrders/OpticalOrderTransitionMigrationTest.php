<?php

/**
 * Tests for the transition migration that adds direct commercial fields
 * to quotations, quotation_items, job_orders, and billing_records.
 */

use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Quotation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('quotations table has direct commercial columns', function () {
    expect(Schema::hasColumn('quotations', 'subtotal'))->toBeTrue()
        ->and(Schema::hasColumn('quotations', 'discount_amount'))->toBeTrue()
        ->and(Schema::hasColumn('quotations', 'total'))->toBeTrue()
        ->and(Schema::hasColumn('quotations', 'confirmed_by'))->toBeTrue()
        ->and(Schema::hasColumn('quotations', 'confirmed_at'))->toBeTrue();
});

test('quotation items table has direct quotation_id column', function () {
    expect(Schema::hasColumn('quotation_items', 'quotation_id'))->toBeTrue();
});

test('job orders table has direct quotation_id column', function () {
    expect(Schema::hasColumn('job_orders', 'quotation_id'))->toBeTrue();
});

test('billing records table has payment due date column', function () {
    expect(Schema::hasColumn('billing_records', 'payment_due_date'))->toBeTrue();
});

test('quotations can store direct totals', function () {
    $quotation = Quotation::factory()->create([
        'subtotal' => 10000,
        'discount_amount' => 1500,
        'total' => 8500,
    ]);

    expect((float) $quotation->fresh()->subtotal)->toBe(10000.0)
        ->and((float) $quotation->fresh()->discount_amount)->toBe(1500.0)
        ->and((float) $quotation->fresh()->total)->toBe(8500.0);
});

test('quotation items can reference quotation directly via DB', function () {
    $quotation = Quotation::factory()->create();

    // Insert directly via DB since model fillability is updated in Task A3
    DB::table('quotation_items')->insert([
        'quotation_id' => $quotation->id,
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $item = DB::table('quotation_items')->where('quotation_id', $quotation->id)->first();

    expect($item)->not->toBeNull()
        ->and($item->quotation_id)->toBe($quotation->id);
});

test('job orders can reference quotation directly', function () {
    $quotation = Quotation::factory()->create();

    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
    ]);

    expect($jobOrder->fresh()->quotation_id)->toBe($quotation->id);
});

test('billing records can store payment due date', function () {
    $billing = BillingRecord::factory()->create([
        'payment_due_date' => '2026-09-01',
    ]);

    expect($billing->fresh()->payment_due_date)->toBeInstanceOf(Carbon::class)
        ->and($billing->fresh()->payment_due_date->format('Y-m-d'))->toBe('2026-09-01');
});

test('billing records payment due date is nullable', function () {
    $billing = BillingRecord::factory()->create([
        'payment_due_date' => null,
    ]);

    expect($billing->fresh()->payment_due_date)->toBeNull();
});

test('direct quotation totals can be queried', function () {
    Quotation::factory()->create(['total' => 5000]);
    Quotation::factory()->create(['total' => 8000]);
    Quotation::factory()->create(['total' => 3000]);

    $highValue = Quotation::where('total', '>=', 5000)->count();

    expect($highValue)->toBe(2);
});
