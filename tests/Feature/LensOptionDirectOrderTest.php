<?php

use App\Actions\OpticalOrders\CreateDirectOpticalOrder;
use App\Enums\CommercialItemKind;
use App\Models\BillingRecordItem;
use App\Models\InventoryMovement;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
});

test('direct optical orders include lens options in billing without inventory movement', function (): void {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $option = LensOption::factory()->create(['price' => 850]);

    $result = app(CreateDirectOpticalOrder::class)->handle(
        patient: $patient,
        creator: $this->staff,
        prescription: $prescription,
        items: [
            ['lens_category_id' => $lensCategory->id, 'description' => 'Package', 'quantity' => 1, 'unit_price' => 3000],
            ['lens_option_id' => $option->id, 'description' => $option->name, 'quantity' => 1, 'unit_price' => 850],
        ],
    );

    $item = $result['job_order']->items()->where('lens_option_id', $option->id)->firstOrFail();

    expect($item->item_kind)->toBe(CommercialItemKind::LensOption)
        ->and($item->item_snapshot['lens_option_name'])->toBe($option->name)
        ->and((float) $result['billing_record']->total_amount)->toBe(3850.0)
        ->and(BillingRecordItem::query()->where('job_order_item_id', $item->id)->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('job_order_id', $result['job_order']->id)->count())->toBe(0);
});

test('direct optical orders reject a lens option without a package', function (): void {
    $option = LensOption::factory()->create();

    app(CreateDirectOpticalOrder::class)->handle(
        patient: Patient::factory()->create(),
        creator: $this->staff,
        items: [[
            'lens_option_id' => $option->id,
            'description' => $option->name,
            'quantity' => 1,
            'unit_price' => $option->price,
        ]],
    );
})->throws(ValidationException::class, 'require a lens package');

test('direct optical orders reject inactive lens options', function (): void {
    $option = LensOption::factory()->inactive()->create();

    app(CreateDirectOpticalOrder::class)->handle(
        patient: Patient::factory()->create(),
        creator: $this->staff,
        items: [[
            'lens_option_id' => $option->id,
            'description' => $option->name,
            'quantity' => 1,
            'unit_price' => $option->price,
        ]],
    );
})->throws(ValidationException::class);

test('direct optical orders reject a lens option item without an option id', function (): void {
    app(CreateDirectOpticalOrder::class)->handle(
        patient: Patient::factory()->create(),
        creator: $this->staff,
        items: [[
            'item_kind' => 'lens_option',
            'description' => 'Lens option',
            'quantity' => 1,
            'unit_price' => 850,
        ]],
    );
})->throws(ValidationException::class, 'requires a catalog lens option');
