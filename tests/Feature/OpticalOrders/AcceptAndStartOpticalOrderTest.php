<?php

use App\Actions\OpticalOrders\AcceptAndStartOpticalOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Quotation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('accepting a quotation creates a job order and billing record', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($result['job_order'])->toBeInstanceOf(JobOrder::class)
        ->and($result['job_order']->status)->toBe(JobOrderStatus::Queued)
        ->and($result['billing_record'])->toBeInstanceOf(BillingRecord::class)
        ->and($result['billing_record']->status)->toBe(BillingRecordStatus::Unpaid);
});

test('accepting is idempotent - returns existing records', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);

    $first = app(AcceptAndStartOpticalOrder::class)->handle($quotation);
    $second = app(AcceptAndStartOpticalOrder::class)->handle($quotation->fresh());

    expect($first['job_order']->id)->toBe($second['job_order']->id)
        ->and($first['billing_record']->id)->toBe($second['billing_record']->id);
});

test('already accepted quotation can be started', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($result['job_order'])->toBeInstanceOf(JobOrder::class);
});

test('presented quotation is accepted during start', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);

    app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
});
