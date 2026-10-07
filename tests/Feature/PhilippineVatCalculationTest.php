<?php

use App\Actions\BillingRecords\CalculatePhilippineVatSummary;
use App\Enums\DiscountType;
use App\Enums\VatTreatment;
use Illuminate\Validation\ValidationException;

function calculateVatSummary(array $items, DiscountType $discountType = DiscountType::None, float $discountAmount = 0): array
{
    return app(CalculatePhilippineVatSummary::class)->handle(
        items: $items,
        discountType: $discountType,
        discountAmount: $discountAmount,
    );
}

test('extracts 12 percent VAT from inclusive taxable sales', function () {
    $summary = calculateVatSummary([
        [
            'amount' => 1120,
            'vat_treatment' => VatTreatment::Vatable->value,
            'statutory_discount_eligible' => false,
        ],
    ]);

    expect($summary)->toMatchArray([
        'subtotal_cents' => 112000,
        'vatable_sales_cents' => 100000,
        'vat_amount_cents' => 12000,
        'vat_exempt_sales_cents' => 0,
        'zero_rated_sales_cents' => 0,
        'discount_cents' => 0,
        'vat_exemption_cents' => 0,
        'total_cents' => 112000,
    ]);
});

test('breaks mixed sales into VATable exempt and zero-rated amounts', function () {
    $summary = calculateVatSummary([
        [
            'amount' => 1120,
            'vat_treatment' => VatTreatment::Vatable->value,
            'statutory_discount_eligible' => false,
        ],
        [
            'amount' => 560,
            'vat_treatment' => VatTreatment::Exempt->value,
            'statutory_discount_eligible' => false,
        ],
        [
            'amount' => 224,
            'vat_treatment' => VatTreatment::ZeroRated->value,
            'statutory_discount_eligible' => false,
        ],
    ]);

    expect($summary)->toMatchArray([
        'subtotal_cents' => 190400,
        'vatable_sales_cents' => 100000,
        'vat_amount_cents' => 12000,
        'vat_exempt_sales_cents' => 56000,
        'zero_rated_sales_cents' => 22400,
        'total_cents' => 190400,
    ]);
});

test('removes inclusive VAT before applying a statutory discount to eligible lines', function () {
    $summary = calculateVatSummary([
        [
            'amount' => 1120,
            'vat_treatment' => VatTreatment::Vatable->value,
            'statutory_discount_eligible' => true,
        ],
        [
            'amount' => 1120,
            'vat_treatment' => VatTreatment::Vatable->value,
            'statutory_discount_eligible' => false,
        ],
    ], DiscountType::Pwd);

    expect($summary)->toMatchArray([
        'subtotal_cents' => 224000,
        'vatable_sales_cents' => 100000,
        'vat_amount_cents' => 12000,
        'vat_exempt_sales_cents' => 100000,
        'discount_cents' => 20000,
        'vat_exemption_cents' => 12000,
        'total_cents' => 192000,
    ]);
});

test('applies the statutory discount to eligible VAT-exempt sales without deducting VAT twice', function () {
    $summary = calculateVatSummary([
        [
            'amount' => 500,
            'vat_treatment' => VatTreatment::Exempt->value,
            'statutory_discount_eligible' => true,
        ],
    ], DiscountType::SeniorCitizen);

    expect($summary)->toMatchArray([
        'vat_amount_cents' => 0,
        'vat_exempt_sales_cents' => 50000,
        'discount_cents' => 10000,
        'vat_exemption_cents' => 0,
        'total_cents' => 40000,
    ]);
});

test('allocates a custom discount proportionally before calculating VAT', function () {
    $summary = calculateVatSummary([
        [
            'amount' => 1120,
            'vat_treatment' => VatTreatment::Vatable->value,
            'statutory_discount_eligible' => false,
        ],
        [
            'amount' => 1120,
            'vat_treatment' => VatTreatment::Exempt->value,
            'statutory_discount_eligible' => false,
        ],
    ], DiscountType::Other, 100);

    expect($summary)->toMatchArray([
        'subtotal_cents' => 224000,
        'vatable_sales_cents' => 95536,
        'vat_amount_cents' => 11464,
        'vat_exempt_sales_cents' => 107000,
        'discount_cents' => 10000,
        'total_cents' => 214000,
    ]);
});

test('rejects custom discounts above the inclusive subtotal', function () {
    calculateVatSummary([
        [
            'amount' => 100,
            'vat_treatment' => VatTreatment::Vatable->value,
            'statutory_discount_eligible' => false,
        ],
    ], DiscountType::Other, 101);
})->throws(ValidationException::class, 'Discount cannot exceed subtotal');
