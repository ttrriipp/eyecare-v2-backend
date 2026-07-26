<?php

use App\Actions\Privacy\EvaluateRetention;
use App\Models\LegalHold;
use App\Models\RetentionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('retention categories and review dates are configurable', function () {
    $policy = RetentionPolicy::factory()->create([
        'category' => 'medical_records',
        'retention_days' => 2555,
        'next_review_date' => '2027-01-01',
    ]);

    expect($policy->category)->toBe('medical_records')
        ->and($policy->retention_days)->toBe(2555)
        ->and($policy->next_review_date->toDateString())->toBe('2027-01-01');
});

test('legal holds prevent disposal eligibility', function () {
    RetentionPolicy::factory()->create([
        'category' => 'prescriptions',
        'retention_days' => 365,
        'auto_purge_enabled' => true,
    ]);

    LegalHold::factory()->create(['is_active' => true]);

    $evaluator = new EvaluateRetention;

    // Record is old enough to be disposed
    $oldRecord = Carbon::now()->subDays(400);

    expect($evaluator->isEligibleForDisposal('prescriptions', $oldRecord))->toBeFalse();
});

test('automatic purge is disabled by default', function () {
    RetentionPolicy::factory()->create([
        'category' => 'invoices',
        'retention_days' => 365,
        'auto_purge_enabled' => false,
    ]);

    $evaluator = new EvaluateRetention;
    $oldRecord = Carbon::now()->subDays(400);

    expect($evaluator->isEligibleForDisposal('invoices', $oldRecord))->toBeFalse();
});

test('record is eligible when policy exists and no holds active', function () {
    RetentionPolicy::factory()->create([
        'category' => 'test_category',
        'retention_days' => 30,
        'auto_purge_enabled' => true,
    ]);

    $evaluator = new EvaluateRetention;
    $oldRecord = Carbon::now()->subDays(45);

    expect($evaluator->isEligibleForDisposal('test_category', $oldRecord))->toBeTrue();
});

test('record is not eligible when retention period has not elapsed', function () {
    RetentionPolicy::factory()->create([
        'category' => 'test_category',
        'retention_days' => 365,
        'auto_purge_enabled' => true,
    ]);

    $evaluator = new EvaluateRetention;
    $recentRecord = Carbon::now()->subDays(30);

    expect($evaluator->isEligibleForDisposal('test_category', $recentRecord))->toBeFalse();
});

test('record is not eligible when no policy exists for category', function () {
    $evaluator = new EvaluateRetention;
    $oldRecord = Carbon::now()->subDays(1000);

    expect($evaluator->isEligibleForDisposal('nonexistent_category', $oldRecord))->toBeFalse();
});
