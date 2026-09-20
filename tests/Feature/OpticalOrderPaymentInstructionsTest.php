<?php

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('pending payment order exposes configured payment instructions', function (): void {
    $this->seed(RoleSeeder::class);
    $account = User::factory()->patient()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::PendingPayment,
        'payment_expires_at' => now()->addMinutes(30),
        'total_amount' => 1200,
    ]);
    BillingRecord::factory()->create([
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => BillingRecordStatus::Unpaid,
        'subtotal_amount' => 1200,
        'total_amount' => 1200,
        'balance_due' => 1200,
    ]);

    config([
        'payments.gcash_account_name' => 'Clinic Eyecare',
        'payments.gcash_account_number' => '09171234567',
    ]);

    $this->actingAs($account)
        ->getJson("/api/v1/optical-orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.payment_proof_status', 'not_submitted')
        ->assertJsonPath('data.payment_instructions.method', 'gcash')
        ->assertJsonPath('data.payment_instructions.clinic_account_name', 'Clinic Eyecare')
        ->assertJsonPath('data.payment_instructions.clinic_account_number', '09171234567')
        ->assertJsonPath('data.payment_instructions.amount', '1200.00')
        ->assertJsonPath('data.payment_instructions.order_reference', $order->job_order_number);
});

test('payment instructions are absent after payment review begins', function (): void {
    $this->seed(RoleSeeder::class);
    $account = User::factory()->patient()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => JobOrderStatus::PaymentReview,
        'payment_expires_at' => now()->addMinutes(30),
    ]);

    config([
        'payments.gcash_account_name' => 'Clinic Eyecare',
        'payments.gcash_account_number' => '09171234567',
    ]);

    $this->actingAs($account)
        ->getJson("/api/v1/optical-orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.payment_instructions', null);
});
