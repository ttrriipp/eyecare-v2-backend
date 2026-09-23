<?php

use App\Enums\DiscountProofStatus;
use App\Filament\Resources\AccessoryOrderRequests\Pages\ListAccessoryOrderRequests;
use App\Filament\Resources\AccessoryOrderRequests\Pages\ViewAccessoryOrderRequest;
use App\Filament\Resources\AccessoryOrderRequests\Widgets\AccessoryOrderRequestStatsWidget;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\AccessoryOrderRequest;
use App\Models\AccessoryOrderRequestDiscountProof;
use App\Models\AccessoryOrderRequestItem;
use App\Models\JobOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
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

test('staff can open the linked optical order from an accepted request', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $order = JobOrder::factory()->create([
        'patient_id' => $account->patient->id,
    ]);
    $request = AccessoryOrderRequest::factory()->accepted()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'job_order_id' => $order->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('viewOpticalOrder')
        ->assertActionHasUrl(
            'viewOpticalOrder',
            OpticalOrderResource::getUrl('edit', ['record' => $order]),
        );
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

test('staff can view a patient cancellation reason in the request details', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $reason = 'I selected the wrong accessories.';
    $request = AccessoryOrderRequest::factory()->cancelled()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'encrypted_cancellation_reason' => $reason,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Patient cancellation reason')
        ->assertSee($reason);
});

test('staff can reject an accessory order request with a preset reason', function (): void {
    $this->seed(NotificationStatusSeeder::class);
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

test('staff can reject a discount proof with a preset reason from the order request page', function (): void {
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
        ->callAction('rejectDiscountProof', ['reason_category' => 'unreadable'])
        ->assertNotified('Discount proof rejected');

    expect($proof->fresh()->status)->toBe(DiscountProofStatus::Rejected)
        ->and($proof->fresh()->rejection_reason)->toBe('The proof image is blurry or unreadable.');
});

test('staff must provide custom details when rejecting a discount proof for another reason', function (): void {
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
        ->callAction('rejectDiscountProof', ['reason_category' => 'other'])
        ->assertHasActionErrors(['rejection_details']);

    expect($proof->fresh()->status)->toBe(DiscountProofStatus::Pending);
});

test('staff can provide a custom reason when rejecting a discount proof', function (): void {
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
        ->callAction('rejectDiscountProof', [
            'reason_category' => 'other',
            'rejection_details' => 'Please provide a clearer copy of the document.',
        ])
        ->assertNotified('Discount proof rejected');

    expect($proof->fresh()->status)->toBe(DiscountProofStatus::Rejected)
        ->and($proof->fresh()->rejection_reason)->toBe('Please provide a clearer copy of the document.');
});

test('staff cannot manually set a discount when accepting a verified request', function (): void {
    $staff = User::factory()->staff()->create();
    $account = User::factory()->patient()->create();
    $request = AccessoryOrderRequest::factory()->create([
        'user_id' => $account->id,
        'patient_id' => $account->patient->id,
        'requested_discount_type' => 'pwd',
    ]);
    AccessoryOrderRequestDiscountProof::factory()->create([
        'accessory_order_request_id' => $request->id,
        'user_id' => $account->id,
        'status' => DiscountProofStatus::Accepted,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewAccessoryOrderRequest::class, ['record' => $request->getRouteKey()])
        ->mountAction('accept')
        ->assertMountedActionModalDontSee('Discount Amount');
});
