<?php

use App\Models\OrderPaymentProof;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('active staff can download a private payment proof as an attachment', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $staff = User::factory()->staff()->create();
    $proof = OrderPaymentProof::factory()->create([
        'file_path' => 'payment-proofs/receipt.jpg',
        'original_name' => "receipt\nunsafe.jpg",
        'mime_type' => 'image/jpeg',
    ]);
    Storage::disk('payment_proofs')->put($proof->file_path, 'proof');

    $this->actingAs($staff)
        ->get("/payment-proofs/{$proof->id}/download")
        ->assertDownload('receipt_unsafe.jpg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('optometrist-only accounts cannot download payment proofs', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $optometrist = User::factory()->optometrist()->create();
    $proof = OrderPaymentProof::factory()->create();

    $this->actingAs($optometrist)
        ->get("/payment-proofs/{$proof->id}/download")
        ->assertForbidden();
});
