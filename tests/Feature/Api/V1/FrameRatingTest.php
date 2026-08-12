<?php

use App\Enums\JobOrderStatus;
use App\Models\Brand;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->brand = Brand::factory()->create();
});

test('patient can submit a frame rating for a dispensed job order item', function () {
    $user = User::factory()->patient()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'brand_id' => $this->brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'product_variant_id' => $variant->id,
            'rating' => 5,
            'comment' => 'Excellent frame!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.comment', 'Excellent frame!')
        ->assertJsonPath('data.product_variant_id', $variant->id)
        ->assertJsonStructure(['data' => ['id', 'product_variant_id', 'rating', 'comment', 'revision_number', 'created_at']])
        ->assertJsonMissing(['moderation_reason', 'moderated_by', 'moderated_at', 'is_hidden', 'patient_id', 'deleted_at', 'current_revision_id']);
});

test('product_variant_id is optional and derived from item', function () {
    $user = User::factory()->patient()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'brand_id' => $this->brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'rating' => 4,
            'comment' => 'Good frame',
        ])
        ->assertCreated()
        ->assertJsonPath('data.rating', 4)
        ->assertJsonPath('data.product_variant_id', $variant->id);
});

test('rating can be revised on subsequent submissions', function () {
    $user = User::factory()->patient()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'brand_id' => $this->brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
    ]);

    // First rating
    $this->actingAs($user)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'product_variant_id' => $variant->id,
            'rating' => 4,
            'comment' => 'Good frame',
        ])
        ->assertCreated();

    // Revised rating
    $this->actingAs($user)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'product_variant_id' => $variant->id,
            'rating' => 5,
            'comment' => 'Even better after use!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.revision_number', 2);
});

test('rating is rejected for another patients job order item', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'brand_id' => $this->brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $userB->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
    ]);

    $this->actingAs($userA)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'product_variant_id' => $variant->id,
            'rating' => 5,
        ])
        ->assertForbidden();
});

test('rating is rejected for non-dispensed job orders', function () {
    $user = User::factory()->patient()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'brand_id' => $this->brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::InProgress,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'product_variant_id' => $variant->id,
            'rating' => 5,
        ])
        ->assertUnprocessable();
});

test('rating is rejected when variant does not match job order item', function () {
    $user = User::factory()->patient()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'brand_id' => $this->brand->id]);
    $variantA = ProductVariant::factory()->create(['product_id' => $frame->id]);
    $variantB = ProductVariant::factory()->create(['product_id' => $frame->id]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variantA->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'product_variant_id' => $variantB->id,
            'rating' => 5,
        ])
        ->assertUnprocessable();
});

test('rating is rejected when dispensing event does not belong to job order', function () {
    $user = User::factory()->patient()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'brand_id' => $this->brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
    ]);
    $otherJobOrder = JobOrder::factory()->create(['patient_id' => $user->patient->id]);
    $dispensingEvent = DispensingEvent::factory()->create(['job_order_id' => $otherJobOrder->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'product_variant_id' => $variant->id,
            'rating' => 5,
            'dispensing_event_id' => $dispensingEvent->id,
        ])
        ->assertUnprocessable();
});

test('rating requires authentication', function () {
    $this->postJson('/api/v1/optical-order-items/1/rating', [])->assertUnauthorized();
});
