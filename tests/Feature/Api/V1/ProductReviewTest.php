<?php

use App\Models\FrameRating;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->account = User::factory()->patient()->create();
    config(['filesystems.product_review_attachments_disk' => 'product_review_attachments']);
    Storage::fake('product_review_attachments');
});

function createProductReviewRating(
    ProductVariant $variant,
    int $stars,
    ?string $comment,
    ?Carbon $consentedAt = null,
    bool $hidden = false,
    ?string $attachmentPath = null,
    ?Carbon $attachmentConsentedAt = null,
): FrameRating {
    $patient = User::factory()->patient()->create()->patient;
    $rating = FrameRating::factory()->create([
        'patient_id' => $patient->id,
        'product_variant_id' => $variant->id,
        'rating' => $stars,
        'comment' => $comment,
        'is_hidden' => $hidden,
        'attachment_path' => $attachmentPath,
        'attachment_public_id' => $attachmentPath === null ? null : (string) Str::uuid(),
        'attachment_mime_type' => $attachmentPath === null ? null : 'image/png',
        'public_attachment_consent_at' => $attachmentConsentedAt,
    ]);

    $rating->forceFill(['public_display_consent_at' => $consentedAt])->save();

    return $rating->refresh();
}

function createReviewAccessoryVariant(User $account): ProductVariant
{
    $product = Product::factory()->accessory()->create();
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'received_by' => $account->id,
        'quantity_on_hand' => 10,
        'expires_on' => now()->addMonths(6)->toDateString(),
    ]);

    return $variant;
}

test('frame review list includes only consented visible comments and keeps aggregates unchanged', function (): void {
    $frame = Product::factory()->create(['product_type' => 'frame']);
    $variants = ProductVariant::factory()->count(5)->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => false,
    ]);

    $visiblePath = 'product-review-attachments/visible.png';
    $hiddenPath = 'product-review-attachments/hidden.png';
    $privatePath = 'product-review-attachments/private.png';
    Storage::disk('product_review_attachments')->put($visiblePath, 'fixture image');
    Storage::disk('product_review_attachments')->put($hiddenPath, 'hidden image');
    Storage::disk('product_review_attachments')->put($privatePath, 'private image');

    $visible = createProductReviewRating(
        $variants[0],
        5,
        'Good and comfortable.',
        now(),
        attachmentPath: $visiblePath,
        attachmentConsentedAt: now(),
    );
    $hidden = createProductReviewRating(
        $variants[1],
        1,
        'Hidden comment.',
        now(),
        hidden: true,
        attachmentPath: $hiddenPath,
        attachmentConsentedAt: now(),
    );
    $private = createProductReviewRating(
        $variants[2],
        2,
        'Legacy private comment.',
        attachmentPath: $privatePath,
        attachmentConsentedAt: now(),
    );
    createProductReviewRating($variants[3], 4, '   ', now());
    $deleted = createProductReviewRating($variants[4], 3, 'Deleted comment.', now());
    $deleted->delete();

    $this->actingAs($this->account)
        ->getJson("/api/v1/frames/{$frame->id}/reviews")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rating', 5)
        ->assertJsonPath('data.0.comment', 'Good and comfortable.')
        ->assertJsonPath('data.0.created_at', $visible->created_at->toISOString())
        ->assertJsonPath('data.0.attachment_url', route('api.v1.frames.reviews.attachments.show', [
            'frame' => $frame->id,
            'attachment' => $visible->attachment_public_id,
        ], false))
        ->assertJsonPath('meta.total', 1);

    $review = $this->getJson("/api/v1/frames/{$frame->id}/reviews")->json('data.0');

    expect(array_keys($review))->toBe(['rating', 'comment', 'created_at', 'attachment_url'])
        ->and($review)->not->toHaveKey('id')
        ->and($review)->not->toHaveKey('patient_id')
        ->and($review)->not->toHaveKey('moderated_by');

    $this->actingAs($this->account)
        ->get($review['attachment_url'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertStreamedContent('fixture image');

    $this->actingAs($this->account)
        ->get("/api/v1/frames/{$frame->id}/reviews/attachments/{$hidden->attachment_public_id}")
        ->assertNotFound();

    $this->actingAs($this->account)
        ->get("/api/v1/frames/{$frame->id}/reviews/attachments/{$private->attachment_public_id}")
        ->assertNotFound();

    $visible->forceFill(['public_attachment_consent_at' => null])->save();

    $this->actingAs($this->account)
        ->get($review['attachment_url'])
        ->assertNotFound();

    $this->getJson("/api/v1/frames/{$frame->id}")
        ->assertOk()
        ->assertJsonPath('data.average_rating', 3)
        ->assertJsonPath('data.rating_count', 4);
});

test('frame review list is account-authenticated and follows frame visibility rules', function (): void {
    $frame = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => false,
    ]);
    createProductReviewRating($variant, 5, 'Good frame.', now());

    $this->getJson("/api/v1/frames/{$frame->id}/reviews")
        ->assertUnauthorized();

    $unlinkedAccount = User::factory()->create();

    $this->actingAs($unlinkedAccount)
        ->getJson("/api/v1/frames/{$frame->id}/reviews")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $inactiveFrame = Product::factory()->inactive()->create(['product_type' => 'frame']);

    $this->actingAs($unlinkedAccount)
        ->getJson("/api/v1/frames/{$inactiveFrame->id}/reviews")
        ->assertNotFound();
});

test('product review pagination is bounded and newest first', function (): void {
    $frame = Product::factory()->create(['product_type' => 'frame']);
    $variants = ProductVariant::factory()->count(3)->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => false,
    ]);

    foreach ($variants as $index => $variant) {
        $review = createProductReviewRating($variant, 5, "Review {$index}", now());
        $review->forceFill(['created_at' => now()->subDays(2 - $index)])->save();
    }

    $this->actingAs($this->account)
        ->getJson("/api/v1/frames/{$frame->id}/reviews?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.comment', 'Review 2')
        ->assertJsonPath('data.1.comment', 'Review 1')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2);

    $this->actingAs($this->account)
        ->getJson("/api/v1/frames/{$frame->id}/reviews?per_page=51")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);
});

test('accessory reviews inherit patient-role catalog access and hide ineligible products', function (): void {
    $variant = createReviewAccessoryVariant($this->account);
    $product = $variant->product;
    $attachmentPath = 'ratings/accessory-review.png';
    Storage::disk('product_review_attachments')->put($attachmentPath, 'accessory image');
    createProductReviewRating(
        $variant,
        4,
        'Comfortable accessory.',
        now(),
        attachmentPath: $attachmentPath,
        attachmentConsentedAt: now(),
    );

    $reviewResponse = $this->actingAs($this->account)
        ->getJson("/api/v1/accessories/{$product->id}/reviews")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rating', 4)
        ->assertJsonPath('data.0.comment', 'Comfortable accessory.')
        ->assertJsonStructure(['data' => [['rating', 'comment', 'created_at', 'attachment_url']], 'links', 'meta']);

    $this->actingAs($this->account)
        ->get($reviewResponse->json('data.0.attachment_url'))
        ->assertOk()
        ->assertStreamedContent('accessory image');

    $this->actingAs(User::factory()->staff()->create())
        ->getJson("/api/v1/accessories/{$product->id}/reviews")
        ->assertForbidden();

    $this->actingAs(User::factory()->staff()->create())
        ->get($reviewResponse->json('data.0.attachment_url'))
        ->assertForbidden();

    $unavailable = Product::factory()->accessory()->create();

    $this->actingAs($this->account)
        ->getJson("/api/v1/accessories/{$unavailable->id}/reviews")
        ->assertNotFound();
});
