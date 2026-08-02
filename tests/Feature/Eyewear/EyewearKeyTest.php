<?php

use App\Models\JobOrder;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('new quotation receives an eyewear key on creation', function () {
    $quotation = Quotation::factory()->create();

    expect($quotation->eyewear_key)
        ->toBeString()
        ->toStartWith('eyw_')
        ->toHaveLength(30); // eyw_ + 26 ULID chars
});

test('new job order receives an eyewear key on creation', function () {
    $jobOrder = JobOrder::factory()->create();

    expect($jobOrder->eyewear_key)
        ->toBeString()
        ->toStartWith('eyw_')
        ->toHaveLength(30);
});

test('eyewear keys are unique across quotations', function () {
    $q1 = Quotation::factory()->create();
    $q2 = Quotation::factory()->create();

    expect($q1->eyewear_key)->not->toBe($q2->eyewear_key);
});

test('eyewear keys are unique across job orders', function () {
    $j1 = JobOrder::factory()->create();
    $j2 = JobOrder::factory()->create();

    expect($j1->eyewear_key)->not->toBe($j2->eyewear_key);
});

test('job order linked to quotation inherits its eyewear key', function () {
    $quotation = Quotation::factory()->create(['status' => 'accepted', 'total' => 5000]);

    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'eyewear_key' => $quotation->eyewear_key,
    ]);

    expect($jobOrder->eyewear_key)->toBe($quotation->eyewear_key);
});

test('standalone job order generates its own unique key', function () {
    $quotation = Quotation::factory()->create();
    $jobOrder = JobOrder::factory()->create(['quotation_id' => null]);

    expect($jobOrder->eyewear_key)
        ->toBeString()
        ->toStartWith('eyw_')
        ->not->toBe($quotation->eyewear_key);
});

test('eyewear key is persisted and survives a fresh read', function () {
    $quotation = Quotation::factory()->create();
    $key = $quotation->eyewear_key;

    $fresh = Quotation::query()->find($quotation->id);

    expect($fresh->eyewear_key)->toBe($key);
});

test('soft-deleted quotation retains its eyewear key', function () {
    $quotation = Quotation::factory()->create();
    $key = $quotation->eyewear_key;

    $quotation->delete();

    $trashed = Quotation::query()->withTrashed()->find($quotation->id);

    expect($trashed->eyewear_key)->toBe($key);
});

test('eyewear key format is eyw_ followed by valid ulid', function () {
    $quotation = Quotation::factory()->create();
    $key = $quotation->eyewear_key;

    $ulidPart = Str::after($key, 'eyw_');

    expect($ulidPart)->toHaveLength(26)
        ->and(preg_match('/^[0-9A-Z]{26}$/', $ulidPart))->toBe(1);
});
