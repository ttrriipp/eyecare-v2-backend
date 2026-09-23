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

test('active staff can preview a private payment proof inline', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $staff = User::factory()->staff()->create();
    $proof = OrderPaymentProof::factory()->create([
        'file_path' => 'payment-proofs/receipt.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    Storage::disk('payment_proofs')->put($proof->file_path, 'payment proof bytes');

    $response = $this->actingAs($staff)
        ->get(route('payment-proofs.preview', ['proof' => $proof]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent('payment proof bytes');

    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

test('active staff can preview a private PNG payment proof inline', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $staff = User::factory()->staff()->create();
    $proof = OrderPaymentProof::factory()->create([
        'file_path' => 'payment-proofs/receipt.png',
        'mime_type' => 'image/png',
    ]);
    Storage::disk('payment_proofs')->put($proof->file_path, 'payment proof PNG bytes');

    $this->actingAs($staff)
        ->get(route('payment-proofs.preview', ['proof' => $proof]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertStreamedContent('payment proof PNG bytes');
});

test('payment proof preview rejects missing files and unsupported mime types', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $staff = User::factory()->staff()->create();
    $missingProof = OrderPaymentProof::factory()->create([
        'file_path' => 'payment-proofs/missing.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    $unsupportedProof = OrderPaymentProof::factory()->create([
        'file_path' => 'payment-proofs/document.pdf',
        'mime_type' => 'application/pdf',
    ]);
    Storage::disk('payment_proofs')->put($unsupportedProof->file_path, 'not an image');

    $this->actingAs($staff)
        ->get(route('payment-proofs.preview', ['proof' => $missingProof]))
        ->assertNotFound();

    $this->get(route('payment-proofs.preview', ['proof' => $unsupportedProof]))
        ->assertNotFound();
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

test('optometrist-only accounts cannot preview payment proofs', function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('payment_proofs');
    config(['filesystems.payment_proof_disk' => 'payment_proofs']);

    $optometrist = User::factory()->optometrist()->create();
    $proof = OrderPaymentProof::factory()->create();

    $this->actingAs($optometrist)
        ->get(route('payment-proofs.preview', ['proof' => $proof]))
        ->assertForbidden();
});
