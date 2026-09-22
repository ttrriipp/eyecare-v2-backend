<?php

use App\Models\AppointmentRequest;
use App\Models\BillingRecord;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('only staff and admins can decide appointment requests', function (): void {
    $staff = User::factory()->staff()->create();
    $admin = User::factory()->admin()->create();
    $optometrist = User::factory()->optometrist()->create();
    $request = AppointmentRequest::factory()->linked()->create();

    expect($staff->can('accept', $request))->toBeTrue()
        ->and($staff->can('reject', $request))->toBeTrue()
        ->and($admin->can('accept', $request))->toBeTrue()
        ->and($admin->can('reject', $request))->toBeTrue()
        ->and($optometrist->can('accept', $request))->toBeFalse()
        ->and($optometrist->can('reject', $request))->toBeFalse()
        ->and($optometrist->can('view', $request))->toBeTrue();
});

test('only staff and admins can link appointment requests', function (): void {
    $staff = User::factory()->staff()->create();
    $admin = User::factory()->admin()->create();
    $optometrist = User::factory()->optometrist()->create();
    $request = AppointmentRequest::factory()->create(['patient_id' => null]);

    expect($staff->can('link', $request))->toBeTrue()
        ->and($admin->can('link', $request))->toBeTrue()
        ->and($optometrist->can('link', $request))->toBeFalse();
});

test('only staff and admins can record billing payments', function (): void {
    $staff = User::factory()->staff()->create();
    $admin = User::factory()->admin()->create();
    $optometrist = User::factory()->optometrist()->create();
    $billingRecord = BillingRecord::factory()->create();

    expect($staff->can('recordPayment', $billingRecord))->toBeTrue()
        ->and($admin->can('recordPayment', $billingRecord))->toBeTrue()
        ->and($optometrist->can('recordPayment', $billingRecord))->toBeFalse();
});
