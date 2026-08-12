<?php

use App\Actions\JobOrders\CreateJobOrder;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\JobOrder;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('staff can create a job order from an accepted quotation', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);

    expect($jobOrder)->toBeInstanceOf(JobOrder::class)
        ->and($jobOrder->quotation_id)->toBe($quotation->id)
        ->and($jobOrder->status)->toBe(JobOrderStatus::Queued)
        ->and($jobOrder->items)->toHaveCount(1);
});

test('patient cannot create a job order', function () {
    $patient = User::factory()->patient()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);

    app(CreateJobOrder::class)->handle($quotation, $patient);
})->throws(ValidationException::class);

test('job order requires accepted quotation', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);

    app(CreateJobOrder::class)->handle($quotation, $staff);
})->throws(ValidationException::class);

test('duplicate job order from same quotation is prevented', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    app(CreateJobOrder::class)->handle($quotation, $staff);
    app(CreateJobOrder::class)->handle($quotation->fresh(), $staff);
})->throws(ValidationException::class, 'already exists');

test('job order snapshots quotation items', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 8000,
    ]);
    $quotation->items()->createMany([
        ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000],
        ['description' => 'Lens', 'quantity' => 1, 'unit_price' => 3000, 'amount' => 3000],
    ]);

    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);

    expect($jobOrder->items)->toHaveCount(2)
        ->and((float) $jobOrder->total_amount)->toBe(8000.0);
});

test('job order is created from accepted quotation', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);

    expect($jobOrder)->not->toBeNull()
        ->and($jobOrder->quotation_id)->toBe($quotation->id);
});
