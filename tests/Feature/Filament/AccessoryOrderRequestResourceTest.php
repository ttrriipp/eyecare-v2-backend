<?php

use App\Enums\DiscountProofStatus;
use App\Filament\Resources\AccessoryOrderRequests\Pages\ListAccessoryOrderRequests;
use App\Filament\Resources\AccessoryOrderRequests\Pages\ViewAccessoryOrderRequest;
use App\Filament\Resources\AccessoryOrderRequests\Widgets\AccessoryOrderRequestStatsWidget;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestDiscountProof;
use App\Models\AccessoryOrderRequestItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('staff can see order request status KPIs on the list', function (): void {
    $staff = User::factory()->staff()->create();

    AccessoryOrderRequest::factory()->count(2)->create();
    AccessoryOrderRequest::factory()->accepted()->create();
    AccessoryOrderRequest::factory()->rejected()->create();
    AccessoryOrderRequest::factory()->cancelled()->create();

    $this->actingAs($staff);

    Livewire::test(ListAccessoryOrderRequests::class)
        ->assertSee('Pending Review')
        ->assertSee('Accepted')
        ->assertSee('Rejected')
        ->assertSee('Cancelled');

    $widget = Livewire::test(AccessoryOrderRequestStatsWidget::class)->instance();
    $stats = collect((fn (): array => $this->getStats())->call($widget))->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Pending Review')?->getValue())->toBe('2')
        ->and($stats->get('Accepted')?->getValue())->toBe('1')
        ->and($stats->get('Rejected')?->getValue())->toBe('1')
        ->and($stats->get('Cancelled')?->getValue())->toBe('1');
});

test('staff can view the accessory order request details', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $product = Product::factory()->accessory()->create([
        'name' => 'Hydrating Eye Drops',
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'name' => '10 mL',
        'price' => 450,
    ]);
    $request = AccessoryOrderRequest::factory()->create([
        'request_number' => 'ORQ-2026-000099',
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'subtotal_amount' => 450,
    ]);

    AccessoryOrderRequestItem::factory()->create([
        'accessory_order_request_id' => $request->id,
        'product_variant_id' => $variant->id,
        'description' => 'Hydrating Eye Drops — 10 mL',
        'quantity' => 1,
        'unit_price' => 450,
        'amount' => 450,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Request details')
        ->assertSee('Order items')
        ->assertSee($request->request_number)
        ->assertSee($account->patient->full_name)
        ->assertSee('Pending')
        ->assertSee('Hydrating Eye Drops — 10 mL')
        ->assertSee('Submitted')
        ->assertDontSee('Resolved by')
        ->assertDontSee('Resolved at')
        ->assertDontSee('Rejection reason');
});

test('staff can view resolution details for a resolved accessory order request', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $request = AccessoryOrderRequest::factory()->rejected()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Resolution details')
        ->assertSee('Submitted')
        ->assertSee('Resolved by')
        ->assertSee('Resolved at')
        ->assertSee('Rejection reason')
        ->assertSee('Test rejection');
});

test('staff can reject an accessory order request with a preset reason', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $request = AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('reject', ['reason_category' => 'out_of_stock'])
        ->assertHasNoActionErrors()
        ->assertNotified('Request rejected');

    expect($request->fresh()->status->value)->toBe('rejected')
        ->and($request->fresh()->rejection_reason)->toBe('Out of stock');
});

test('staff can review a pending discount proof from the order request page', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $request = AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'requested_discount_type' => 'pwd',
    ]);
    $proof = AccessoryOrderRequestDiscountProof::factory()->create([
        'accessory_order_request_id' => $request->id,
        'user_id' => $account->id,
        'status' => DiscountProofStatus::Pending,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Discount proof')
        ->assertSee('Pending')
        ->callAction('acceptDiscountProof')
        ->assertNotified('Discount proof accepted');

    expect($proof->fresh()->status)->toBe(DiscountProofStatus::Accepted);
});

test('staff can reject a discount proof with a reason from the order request page', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $request = AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'requested_discount_type' => 'senior_citizen',
    ]);
    $proof = AccessoryOrderRequestDiscountProof::factory()->create([
        'accessory_order_request_id' => $request->id,
        'user_id' => $account->id,
        'status' => DiscountProofStatus::Pending,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('rejectDiscountProof', ['reason' => 'The ID image is not readable.'])
        ->assertNotified('Discount proof rejected');

    expect($proof->fresh()->status)->toBe(DiscountProofStatus::Rejected)
        ->and($proof->fresh()->rejection_reason)->toBe('The ID image is not readable.');
});
