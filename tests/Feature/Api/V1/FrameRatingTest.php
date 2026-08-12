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
        ->assertJsonStructure(['data' => ['id', 'product_variant_id', 'rating', 'comment', 'created_at']])
        ->assertJsonMissing(['moderation_reason', 'moderated_by', 'moderated_at', 'is_hidden', 'patient_id', 'deleted_at']);
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
        ->assertJsonPath('data.comment', 'Even better after use!');
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

test('hidden rating still counts toward average_rating and rating_count', function () {
    // Bug fix: hiding a rating should suppress the comment only, not the star.
    // Previously, hidden ratings were excluded from the aggregate entirely.
    $user = User::factory()->patient()->create();
    $brand = Brand::factory()->create();
    $product = Product::factory()->create([
        'product_type' => 'frame',
        'brand_id' => $brand->id,
        'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'ar_eligible' => true,
        'ar_asset_reference' => 'test.usdz',
    ]);

    // Create two ratings: one visible (5 stars), one hidden (1 star)
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
    ]);
    $dispensingEvent = DispensingEvent::factory()->create(['job_order_id' => $jobOrder->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/optical-order-items/{$item->id}/rating", [
            'product_variant_id' => $variant->id,
            'rating' => 5,
            'dispensing_event_id' => $dispensingEvent->id,
        ])
        ->assertCreated();

    // Create a second rating and hide it
    $user2 = User::factory()->patient()->create();
    $jobOrder2 = JobOrder::factory()->create([
        'patient_id' => $user2->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $item2 = JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder2->id,
        'product_variant_id' => $variant->id,
    ]);
    $dispensingEvent2 = DispensingEvent::factory()->create(['job_order_id' => $jobOrder2->id]);

    $this->actingAs($user2)
        ->postJson("/api/v1/optical-order-items/{$item2->id}/rating", [
            'product_variant_id' => $variant->id,
            'rating' => 1,
            'dispensing_event_id' => $dispensingEvent2->id,
        ])
        ->assertCreated();

    // Hide the second rating
    $rating2 = \App\Models\FrameRating::where('patient_id', $user2->patient->id)
        ->where('product_variant_id', $variant->id)
        ->first();
    $rating2->update(['is_hidden' => true, 'moderation_reason' => 'Abusive']);

    // The average should include BOTH ratings: (5 + 1) / 2 = 3.0
    // The count should be 2
    $response = $this->actingAs($user)
        ->getJson("/api/v1/frames/{$product->id}")
        ->assertOk();

    $responseData = $response->json('data');
    expect((float) $responseData['average_rating'])->toBe(3.0);
    expect($responseData['rating_count'])->toBe(2);
});
