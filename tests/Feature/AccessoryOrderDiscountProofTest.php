<?php

use App\Actions\AccessoryOrderRequests\AcceptAccessoryOrderRequest;
use App\Actions\AccessoryOrderRequests\AcceptDiscountProof;
use App\Actions\AccessoryOrderRequests\RejectDiscountProof;
use App\Enums\AccessoryOrderRequestStatus;
use App\Enums\DiscountProofStatus;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestDiscountProof;
use App\Models\AccessoryOrderRequestItem;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('discount_proofs');
    config(['filesystems.discount_proof_disk' => 'discount_proofs']);
    $this->account = User::factory()->patient()->create();
    $this->variant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->accessory(),
        'is_active' => true,
        'price' => 100,
    ]);
});

function createPendingDiscountRequest(User $account, ProductVariant $variant): AccessoryOrderRequest
{
    $request = AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'status' => AccessoryOrderRequestStatus::Pending,
        'requested_discount_type' => 'pwd',
        'subtotal_amount' => 100,
    ]);

    AccessoryOrderRequestItem::factory()->create([
        'accessory_order_request_id' => $request->id,
        'product_variant_id' => $variant->id,
        'description' => 'Daily Care Accessory',
        'quantity' => 1,
        'unit_price' => 100,
        'amount' => 100,
        'item_snapshot' => [
            'product_variant_id' => $variant->id,
            'sku' => $variant->sku,
            'variant_name' => $variant->name,
            'product_name' => $variant->product->name,
            'price' => $variant->price,
            'attributes' => $variant->attributes,
        ],
    ]);

    return $request;
}

test('patient can submit one private discount proof for a requested discount', function (): void {
    $request = createPendingDiscountRequest($this->account, $this->variant);

    $response = $this->actingAs($this->account)
        ->post("/api/v1/accessory-order-requests/{$request->id}/discount-proof", [
            'proof' => UploadedFile::fake()->image('pwd-id.png', 100, 100),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', DiscountProofStatus::Pending->value);

    $proofId = $response->json('data.id');
    $proof = $request->discountProof()->findOrFail($proofId);

    expect($response->json('data'))->not->toHaveKey('file_path')
        ->and($proof->status)->toBe(DiscountProofStatus::Pending);

    Storage::disk('discount_proofs')->assertExists($proof->file_path);
});

test('discount proof is not accepted for an order request without a discount', function (): void {
    $request = createPendingDiscountRequest($this->account, $this->variant);
    $request->update(['requested_discount_type' => 'none']);

    $this->actingAs($this->account)
        ->post("/api/v1/accessory-order-requests/{$request->id}/discount-proof", [
            'proof' => UploadedFile::fake()->image('proof.png', 100, 100),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'DISCOUNT_PROOF_NOT_REQUESTED');
});

test('order request responses expose discount proof status without private metadata', function (): void {
    $request = createPendingDiscountRequest($this->account, $this->variant);

    $this->actingAs($this->account)
        ->getJson('/api/v1/accessory-order-requests?filter=current')
        ->assertOk()
        ->assertJsonPath('data.0.id', $request->id)
        ->assertJsonPath('data.0.discount_proof_status', 'not_submitted')
        ->assertJsonPath('data.0.discount_proof_rejection_reason', null);

    $this->actingAs($this->account)
        ->post("/api/v1/accessory-order-requests/{$request->id}/discount-proof", [
            'proof' => UploadedFile::fake()->image('pwd-id.png', 100, 100),
        ])
        ->assertCreated();

    $this->actingAs($this->account)
        ->getJson("/api/v1/accessory-order-requests/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.discount_proof_status', 'pending')
        ->assertJsonMissingPath('data.discount_proof.file_path');
});

test('staff can accept a pending discount proof and the action is audited', function (): void {
    $request = createPendingDiscountRequest($this->account, $this->variant);
    $proof = AccessoryOrderRequestDiscountProof::factory()->create([
        'accessory_order_request_id' => $request->id,
        'user_id' => $this->account->id,
        'status' => DiscountProofStatus::Pending,
    ]);
    $reviewer = User::factory()->staff()->create();

    $accepted = app(AcceptDiscountProof::class)->handle(
        proof: $proof,
        reviewer: $reviewer,
    );

    expect($accepted->status)->toBe(DiscountProofStatus::Accepted)
        ->and($accepted->reviewed_by)->toBe($reviewer->id)
        ->and($accepted->reviewed_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'discount_proof.accepted',
        'subject_id' => $proof->id,
        'actor_id' => $reviewer->id,
    ]);
});

test('optometrist-only accounts cannot review discount proofs', function (): void {
    $request = createPendingDiscountRequest($this->account, $this->variant);
    $proof = AccessoryOrderRequestDiscountProof::factory()->create([
        'accessory_order_request_id' => $request->id,
        'user_id' => $this->account->id,
        'status' => DiscountProofStatus::Pending,
    ]);
    $reviewer = User::factory()->optometrist()->create();

    expect(fn () => app(AcceptDiscountProof::class)->handle(
        proof: $proof,
        reviewer: $reviewer,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(RejectDiscountProof::class)->handle(
        proof: $proof,
        reviewer: $reviewer,
        reason: 'Not permitted',
    ))->toThrow(ValidationException::class);
});

test('staff can reject a pending discount proof and the patient can replace it', function (): void {
    $request = createPendingDiscountRequest($this->account, $this->variant);
    $oldProof = AccessoryOrderRequestDiscountProof::factory()->create([
        'accessory_order_request_id' => $request->id,
        'user_id' => $this->account->id,
        'status' => DiscountProofStatus::Pending,
        'file_path' => 'discount-proofs/old.jpg',
    ]);
    Storage::disk('discount_proofs')->put($oldProof->file_path, 'old-proof');
    $reviewer = User::factory()->staff()->create();

    $rejected = app(RejectDiscountProof::class)->handle(
        proof: $oldProof,
        reviewer: $reviewer,
        reason: 'The submitted ID is not readable.',
    );

    expect($rejected->status)->toBe(DiscountProofStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('The submitted ID is not readable.');

    $response = $this->actingAs($this->account)
        ->post("/api/v1/accessory-order-requests/{$request->id}/discount-proof", [
            'proof' => UploadedFile::fake()->image('replacement.png', 100, 100),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', DiscountProofStatus::Pending->value);

    expect($response->json('data.id'))->toBe($oldProof->id)
        ->and($request->fresh('discountProof')->discountProof->rejection_reason)->toBeNull();

    Storage::disk('discount_proofs')->assertMissing('discount-proofs/old.jpg');

    $this->actingAs($this->account)
        ->getJson("/api/v1/accessory-order-requests/{$request->id}")
        ->assertJsonPath('data.discount_proof_status', 'pending')
        ->assertJsonPath('data.discount_proof_rejection_reason', null);
});

test('a requested discount cannot be accepted before its proof is verified', function (): void {
    $this->seed(NotificationStatusSeeder::class);
    InventoryLot::factory()->create([
        'product_variant_id' => $this->variant->id,
        'received_by' => $this->account->id,
        'quantity_on_hand' => 10,
        'expires_on' => now()->addMonths(6)->toDateString(),
    ]);
    $this->variant->update(['stock_quantity' => 10]);
    $request = createPendingDiscountRequest($this->account, $this->variant);
    $reviewer = User::factory()->admin()->create();

    expect(fn () => app(AcceptAccessoryOrderRequest::class)->handle(
        orderRequest: $request,
        reviewer: $reviewer,
    ))->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe(AccessoryOrderRequestStatus::Pending);
});

test('an accepted discount proof allows the request to become a pending-payment order', function (): void {
    $this->seed(NotificationStatusSeeder::class);
    InventoryLot::factory()->create([
        'product_variant_id' => $this->variant->id,
        'received_by' => $this->account->id,
        'quantity_on_hand' => 10,
        'expires_on' => now()->addMonths(6)->toDateString(),
    ]);
    $this->variant->update(['stock_quantity' => 10]);

    $request = createPendingDiscountRequest($this->account, $this->variant);
    $proof = AccessoryOrderRequestDiscountProof::factory()->create([
        'accessory_order_request_id' => $request->id,
        'user_id' => $this->account->id,
        'status' => DiscountProofStatus::Accepted,
    ]);
    $reviewer = User::factory()->admin()->create();

    $result = app(AcceptAccessoryOrderRequest::class)->handle(
        orderRequest: $request,
        reviewer: $reviewer,
    );

    expect($proof->fresh()->status)->toBe(DiscountProofStatus::Accepted)
        ->and($result['request']->status)->toBe(AccessoryOrderRequestStatus::Accepted)
        ->and($result['order']->status->value)->toBe('pending_payment');
});

test('active staff can download a private discount proof', function (): void {
    $reviewer = User::factory()->staff()->create();
    $proof = AccessoryOrderRequestDiscountProof::factory()->create([
        'original_name' => "pwd\nproof.jpg",
        'mime_type' => 'image/jpeg',
        'file_path' => 'discount-proofs/pwd.jpg',
    ]);
    Storage::disk('discount_proofs')->put($proof->file_path, 'proof');

    $this->actingAs($reviewer)
        ->get("/discount-proofs/{$proof->id}/download")
        ->assertDownload('pwd_proof.jpg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('active staff can preview a private discount proof inline', function (): void {
    $reviewer = User::factory()->staff()->create();
    $proof = AccessoryOrderRequestDiscountProof::factory()->create([
        'original_name' => "pwd\nproof.jpg",
        'mime_type' => 'image/jpeg',
        'file_path' => 'discount-proofs/pwd.jpg',
    ]);
    Storage::disk('discount_proofs')->put($proof->file_path, 'proof image bytes');

    $this->actingAs($reviewer)
        ->get(route('discount-proofs.preview', ['proof' => $proof]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertStreamedContent('proof image bytes');
});

test('discount proof preview rejects missing files and unsupported mime types', function (): void {
    $reviewer = User::factory()->staff()->create();
    $missingProof = AccessoryOrderRequestDiscountProof::factory()->create([
        'mime_type' => 'image/jpeg',
        'file_path' => 'discount-proofs/missing.jpg',
    ]);
    $unsupportedProof = AccessoryOrderRequestDiscountProof::factory()->create([
        'mime_type' => 'application/pdf',
        'file_path' => 'discount-proofs/document.pdf',
    ]);
    Storage::disk('discount_proofs')->put($unsupportedProof->file_path, 'not an image');

    $this->actingAs($reviewer)
        ->get(route('discount-proofs.preview', ['proof' => $missingProof]))
        ->assertNotFound();

    $this->get(route('discount-proofs.preview', ['proof' => $unsupportedProof]))
        ->assertNotFound();
});

test('optometrist-only accounts cannot download discount proofs', function (): void {
    $optometrist = User::factory()->optometrist()->create();
    $proof = AccessoryOrderRequestDiscountProof::factory()->create();

    $this->actingAs($optometrist)
        ->get("/discount-proofs/{$proof->id}/download")
        ->assertForbidden();

    $this->get(route('discount-proofs.preview', ['proof' => $proof]))
        ->assertForbidden();
});
