<?php

use App\Actions\BillingRecords\BillRemainingQuotedServices;
use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Enums\BillingItemSourceKind;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
});

test('bills a service skipped at confirm-sale time onto the same open bill', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    QuotationItem::factory()->product()->create([
        'quotation_id' => $quotation->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);
    $skippedService = QuotationItem::factory()->service()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Anti-glare coating',
        'quantity' => 1,
        'unit_price' => 500,
        'amount' => 500,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        performedServiceItemIds: [],
    );

    expect($quotation->fresh()->unbilledServiceItems)->toHaveCount(1);

    $billingRecord = app(BillRemainingQuotedServices::class)->handle(
        quotation: $quotation->fresh(),
        quotationItemIds: [$skippedService->id],
    );

    expect($billingRecord->id)->toBe($result['billing_record']->id)
        ->and($billingRecord->items()->where('quotation_item_id', $skippedService->id)->exists())->toBeTrue()
        ->and($billingRecord->items()->where('quotation_item_id', $skippedService->id)->first()->source_kind)->toBe(BillingItemSourceKind::Quotation)
        ->and($quotation->fresh()->unbilledServiceItems)->toHaveCount(0);
});

test('cannot bill services on a quotation that was never confirmed', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    $service = QuotationItem::factory()->service()->create(['quotation_id' => $quotation->id]);

    app(BillRemainingQuotedServices::class)->handle($quotation, [$service->id]);
})->throws(ValidationException::class);

test('billing an already-billed service is a no-op, not a duplicate charge', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    $service = QuotationItem::factory()->service()->create([
        'quotation_id' => $quotation->id,
        'quantity' => 1,
        'unit_price' => 500,
        'amount' => 500,
    ]);

    app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        performedServiceItemIds: [$service->id],
    );

    $billingRecord = app(BillRemainingQuotedServices::class)->handle(
        quotation: $quotation->fresh(),
        quotationItemIds: [$service->id],
    );

    expect($billingRecord->items()->where('quotation_item_id', $service->id)->count())->toBe(1);
});
