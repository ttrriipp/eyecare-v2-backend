<?php

use App\Models\FrameRating;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->account = User::factory()->patient()->create();
});

function createAccessoryVariant(User $account, array $product = [], array $variant = []): ProductVariant
{
    $accessory = Product::factory()->accessory()->create($product);
    $productVariant = ProductVariant::factory()->create(array_merge([
        'product_id' => $accessory->id,
        'is_active' => true,
        'price' => 149.50,
        'cost_price' => 50,
        'stock_quantity' => 10,
    ], $variant));

    InventoryLot::factory()->create([
        'product_variant_id' => $productVariant->id,
        'received_by' => $account->id,
        'quantity_on_hand' => 10,
        'expires_on' => now()->addMonths(6)->toDateString(),
    ]);

    return $productVariant;
}

test('accessory catalog exposes patient-safe variants and rating aggregates', function (): void {
    $variant = createAccessoryVariant($this->account, [
        'name' => 'Daily Care Kit',
        'images' => ['catalog/daily-care.jpg', '../private-proof.png', 'https://example.test/remote.jpg'],
    ], [
        'images' => ['catalog/variant.jpg', '/private/file.png'],
    ]);

    FrameRating::factory()->create([
        'patient_id' => $this->account->patient->id,
        'product_variant_id' => $variant->id,
        'rating' => 4,
    ]);

    $response = $this->actingAs($this->account)
        ->getJson('/api/v1/accessories')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Daily Care Kit')
        ->assertJsonPath('data.0.average_rating', 4)
        ->assertJsonPath('data.0.rating_count', 1)
        ->assertJsonPath('data.0.variants.0.price', '149.50')
        ->assertJsonPath('data.0.variants.0.availability', 'available');

    $product = $response->json('data.0');
    $variantData = $product['variants'][0];

    expect($product['images'])->toBe(['catalog/daily-care.jpg'])
        ->and($variantData['images'])->toBe(['catalog/variant.jpg'])
        ->and($variantData)->not->toHaveKey('cost_price')
        ->and($variantData)->not->toHaveKey('stock_quantity')
        ->and($variantData)->not->toHaveKey('low_stock_threshold');
});

test('accessory catalog requires authentication', function (): void {
    $variant = createAccessoryVariant($this->account, ['name' => 'Authentication Kit']);
    $accessory = $variant->product;

    $this->getJson('/api/v1/accessories')
        ->assertUnauthorized();

    $this->getJson("/api/v1/accessories/{$accessory->id}")
        ->assertUnauthorized();
});

test('accessory catalog remains restricted to patient-role accounts', function (): void {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)
        ->getJson('/api/v1/accessories')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PATIENT_ROLE_REQUIRED');
});

test('rejects the retired prescription placement filter', function (): void {
    $this->actingAs($this->account)
        ->getJson('/api/v1/accessories?placement=prescription')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['placement']);
});

test('accessory catalog supports minimum rating and rated filters', function (): void {
    $ratedVariant = createAccessoryVariant($this->account, ['name' => 'Rated Care Kit']);
    FrameRating::factory()->create([
        'patient_id' => $this->account->patient->id,
        'product_variant_id' => $ratedVariant->id,
        'rating' => 5,
    ]);

    createAccessoryVariant($this->account, ['name' => 'Unrated Care Kit']);

    $this->actingAs($this->account)
        ->getJson('/api/v1/accessories?minimum_rating=4')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Rated Care Kit');

    $this->actingAs($this->account)
        ->getJson('/api/v1/accessories?rated=unrated')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Unrated Care Kit');
});

test('soft-deleted ratings do not affect accessory aggregates or rated filters', function (): void {
    $variant = createAccessoryVariant($this->account, ['name' => 'Archived Rating Kit']);
    $rating = FrameRating::factory()->create([
        'patient_id' => $this->account->patient->id,
        'product_variant_id' => $variant->id,
        'rating' => 5,
    ]);
    $rating->delete();

    $this->actingAs($this->account)
        ->getJson('/api/v1/accessories')
        ->assertOk()
        ->assertJsonPath('data.0.average_rating', null)
        ->assertJsonPath('data.0.rating_count', 0);

    $this->actingAs($this->account)
        ->getJson('/api/v1/accessories?rated=rated')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
