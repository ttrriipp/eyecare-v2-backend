<?php

/**
 * Tests for optical commerce API privacy.
 *
 * @see tasks/todo.md Task 40
 */

use App\Enums\CommercialItemKind;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->patient = User::factory()->patient()->create();
});

test('patient resources retain descriptions, quantities, prices, and status', function () {
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
        'status' => 'queued',
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk();

    $response->assertJsonStructure([
        'data' => [
            'id',
            'order_number',
            'status',
            'total_amount',
            'items' => [
                '*' => ['id', 'description', 'quantity', 'unit_price', 'amount', 'image_url'],
            ],
        ],
    ]);
});

test('patient optical order resources present confirmed lens options additively', function (): void {
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);
    $option = LensOption::factory()->create();
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Confirmed coating description',
        'unit_price' => 850,
        'amount' => 850,
        'lens_option_id' => $option->id,
        'item_kind' => CommercialItemKind::LensOption,
        'item_snapshot' => [
            'lens_option_id' => $option->id,
            'lens_option_name' => $option->name,
        ],
    ]);

    $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.description', 'Confirmed coating description')
        ->assertJsonPath('data.items.0.unit_price', '850.00')
        ->assertJsonPath('data.items.0.item_kind', 'lens_option')
        ->assertJsonPath('data.items.0.lens_option_id', $option->id)
        ->assertJsonMissingPath('data.items.0.item_snapshot');

    expect($item->exists)->toBeTrue();
});

test('optical order frame items expose their primary catalog image in list and detail responses', function (): void {
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'images' => ['products/everyday-frame.jpg'],
    ]);
    $frameVariant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'images' => [
            'ar-assets/everyday-frame.glb',
            '/var/www/html/storage/app/public/variants/internal-frame.jpg',
            'https://admin.example.test/variants/internal-frame.jpg',
            'variants/everyday-frame-front.jpg',
            'variants/everyday-frame-side.jpg',
        ],
    ]);
    $contactLens = Product::factory()->contactLens()->create();
    $contactLensVariant = ProductVariant::factory()->create([
        'product_id' => $contactLens->id,
        'images' => ['variants/contact-lens.jpg'],
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);
    $frameItem = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $frameVariant->id,
        'item_kind' => CommercialItemKind::Frame,
    ]);
    $contactLensItem = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $contactLensVariant->id,
        'item_kind' => CommercialItemKind::ContactLens,
    ]);
    $lensPackageItem = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $listItems = collect($this->actingAs($this->patient)
        ->getJson('/api/v1/optical-orders')
        ->assertOk()
        ->json('data.0.items'))
        ->keyBy('id');

    expect($listItems->get($frameItem->id)['image_url'])
        ->toBe('variants/everyday-frame-front.jpg')
        ->and($listItems->get($contactLensItem->id)['image_url'])->toBeNull()
        ->and($listItems->get($lensPackageItem->id)['image_url'])->toBeNull();

    $detailItems = collect($this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk()
        ->json('data.items'))
        ->keyBy('id');

    expect($detailItems->get($frameItem->id)['image_url'])
        ->toBe('variants/everyday-frame-front.jpg')
        ->and($detailItems->get($contactLensItem->id)['image_url'])->toBeNull()
        ->and($detailItems->get($lensPackageItem->id)['image_url'])->toBeNull();
});

test('optical order frame image falls back to the product image when the variant has none', function (): void {
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'images' => ['products/classic-frame.jpg'],
    ]);
    $frameVariant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'images' => [],
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);
    $frameItem = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $frameVariant->id,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $frameItem->id)
        ->assertJsonPath('data.items.0.image_url', 'products/classic-frame.jpg');
});

test('optical order frame image is null when both catalog image collections are empty', function (): void {
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'images' => [],
    ]);
    $frameVariant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'images' => [],
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);
    $frameItem = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $frameVariant->id,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $frameItem->id)
        ->assertJsonPath('data.items.0.image_url', null);
});

test('supplier references are absent from patient resources', function () {
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
        'supplier_invoice_number' => 'INV-001',
        'uses_external_supplier' => true,
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk();

    $response->assertJsonMissing([
        'supplier_invoice_number',
        'uses_external_supplier',
    ]);
});

test('override reason is absent from patient resources', function () {
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);

    $response = $this->actingAs($this->patient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertOk();

    $response->assertJsonMissing([
        'balance_override_reason',
        'balance_override_by',
    ]);
});

test('ownership scoping remains intact', function () {
    $otherPatient = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $this->patient->patient->id,
    ]);

    $this->actingAs($otherPatient)
        ->getJson("/api/v1/optical-orders/{$jobOrder->id}")
        ->assertNotFound();
});
