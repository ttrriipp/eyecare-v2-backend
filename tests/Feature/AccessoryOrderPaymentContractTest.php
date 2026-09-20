<?php

use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\JobOrderStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\JobOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);
    $this->account = User::factory()->patient()->create();
});

function createAcceptedPendingPaymentOrder(User $account, JobOrderStatus $status = JobOrderStatus::PendingPayment): JobOrder
{
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
        'status' => $status,
        'payment_expires_at' => now()->addMinutes(30),
    ]);
    AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
        'status' => AccessoryOrderRequestStatus::Accepted,
    ]);

    return $order;
}

test('payment proof upload returns the stable expired-window error', function (): void {
    $order = createAcceptedPendingPaymentOrder($this->account);
    $order->update(['payment_expires_at' => now()->subMinute()]);

    $this->actingAs($this->account)
        ->post("/api/v1/optical-orders/{$order->id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('proof.png', 100, 100),
            'sender_name' => 'Patient',
            'reference_number' => 'GCASH-EXPIRED-1',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PAYMENT_WINDOW_EXPIRED');
});

test('payment proof upload returns a safe created response and moves the order to review', function (): void {
    $order = createAcceptedPendingPaymentOrder($this->account);

    $response = $this->actingAs($this->account)
        ->post("/api/v1/optical-orders/{$order->id}/payment-proof", [
            'proof' => UploadedFile::fake()->image('proof.png', 100, 100),
            'sender_name' => 'Patient',
            'reference_number' => 'GCASH-VALID-1',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    expect($response->json('data'))->not->toHaveKey('file_path')
        ->and($order->fresh()->status)->toBe(JobOrderStatus::PaymentReview);
});
