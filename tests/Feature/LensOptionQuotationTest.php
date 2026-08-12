<?php

use App\Actions\Quotations\BuildQuotationItemSnapshot;
use App\Actions\Quotations\ConfirmQuotationSale;
use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\CommercialItemKind;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Models\BillingRecordItem;
use App\Models\Encounter;
use App\Models\InventoryMovement;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
});

function quotationWithLensBuild(LensCategory $lensCategory, LensOption ...$options): Quotation
{
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
    ]);

    $quotation->items()->create([
        'description' => $lensCategory->name,
        'quantity' => 1,
        'unit_price' => $lensCategory->price,
        'amount' => $lensCategory->price,
        'lens_category_id' => $lensCategory->id,
        'item_kind' => CommercialItemKind::LensPackage,
        'item_snapshot' => [
            'lens_category_id' => $lensCategory->id,
            'lens_category_name' => $lensCategory->name,
        ],
    ]);

    foreach ($options as $option) {
        $quotation->items()->create([
            'description' => $option->name,
            'quantity' => 1,
            'unit_price' => $option->price,
            'amount' => $option->price,
            'lens_option_id' => $option->id,
            'item_kind' => CommercialItemKind::LensOption,
            'item_snapshot' => [
                'lens_option_id' => $option->id,
                'lens_option_name' => $option->name,
            ],
        ]);
    }

    $quotation->update([
        'subtotal' => $quotation->items()->sum('amount'),
        'total' => $quotation->items()->sum('amount'),
    ]);

    return $quotation->fresh(['items']);
}

test('lens option snapshots retain catalog identity and name', function (): void {
    $option = LensOption::factory()->create([
        'name' => 'Anti-reflective coating',
        'price' => 850,
    ]);

    $result = app(BuildQuotationItemSnapshot::class)->handle(lensOptionId: $option->id);

    expect($result['item_kind'])->toBe(CommercialItemKind::LensOption)
        ->and($result['item_snapshot'])->toBe([
            'lens_option_id' => $option->id,
            'lens_option_name' => 'Anti-reflective coating',
        ]);
});

test('creating a quotation accepts a package with multiple different lens options', function (): void {
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $antiReflective = LensOption::factory()->create(['name' => 'Anti-reflective coating', 'price' => 850]);
    $photochromic = LensOption::factory()->create(['name' => 'Photochromic treatment', 'price' => 1250]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        encounter: $encounter,
        prescription: $prescription,
        data: [
            'items' => [
                [
                    'item_kind' => 'lens',
                    'lens_category_id' => $lensCategory->id,
                    'description' => $lensCategory->name,
                    'quantity' => 1,
                    'unit_price' => 3000,
                ],
                [
                    'item_kind' => 'lens_option',
                    'lens_option_id' => $antiReflective->id,
                    'description' => $antiReflective->name,
                    'quantity' => 1,
                    'unit_price' => 850,
                ],
                [
                    'item_kind' => 'lens_option',
                    'lens_option_id' => $photochromic->id,
                    'description' => $photochromic->name,
                    'quantity' => 1,
                    'unit_price' => 1250,
                ],
            ],
        ],
    );

    expect($quotation->items)->toHaveCount(3)
        ->and($quotation->items->where('item_kind', CommercialItemKind::LensOption))->toHaveCount(2)
        ->and((float) $quotation->total)->toBe(5100.0);
});

test('a lens package without options remains valid', function (): void {
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        encounter: $encounter,
        prescription: $prescription,
        data: [
            'items' => [[
                'item_kind' => 'lens',
                'lens_category_id' => $lensCategory->id,
                'description' => $lensCategory->name,
                'quantity' => 1,
                'unit_price' => 3000,
            ]],
        ],
    );

    expect($quotation->items)->toHaveCount(1)
        ->and($quotation->items->first()->item_kind)->toBe(CommercialItemKind::LensPackage);
});

test('lens option without a package is rejected when creating a quotation', function (): void {
    $option = LensOption::factory()->create();

    app(CreateQuotation::class)->handle(
        patient: Patient::factory()->create(),
        creator: $this->staff,
        data: [
            'items' => [[
                'item_kind' => 'lens_option',
                'lens_option_id' => $option->id,
                'description' => $option->name,
                'quantity' => 1,
                'unit_price' => $option->price,
            ]],
        ],
    );
})->throws(ValidationException::class, 'require a lens package');

test('missing or inactive options are rejected server-side', function (): void {
    $patient = Patient::factory()->create();
    $inactive = LensOption::factory()->inactive()->create();

    foreach ([999999, $inactive->id] as $optionId) {
        expect(fn () => app(CreateQuotation::class)->handle(
            patient: $patient,
            creator: $this->staff,
            data: [
                'items' => [[
                    'item_kind' => 'lens_option',
                    'lens_option_id' => $optionId,
                    'description' => 'Option',
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
            ],
        ))->toThrow(ValidationException::class);
    }
});

test('duplicate lens options are rejected in one quotation', function (): void {
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $option = LensOption::factory()->create();

    expect(fn () => app(UpdateQuotationDraft::class)->handle(
        Quotation::factory()->create(),
        [
            'items' => [
                ['lens_category_id' => $lensCategory->id, 'description' => 'Package', 'quantity' => 1, 'unit_price' => 3000],
                ['lens_option_id' => $option->id, 'description' => 'Option', 'quantity' => 1, 'unit_price' => 100],
                ['lens_option_id' => $option->id, 'description' => 'Option again', 'quantity' => 1, 'unit_price' => 100],
            ],
        ],
    ))->toThrow(ValidationException::class, 'only once');
});

test('draft revision preserves the selected lens option', function (): void {
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $option = LensOption::factory()->create(['price' => 850]);
    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
    ]);

    $updated = app(UpdateQuotationDraft::class)->handle($quotation, [
        'items' => [
            ['lens_category_id' => $lensCategory->id, 'description' => 'Package', 'quantity' => 1, 'unit_price' => 3000],
            ['lens_option_id' => $option->id, 'description' => $option->name, 'quantity' => 1, 'unit_price' => 850],
        ],
    ]);

    $optionItem = $updated->items->firstWhere('lens_option_id', $option->id);

    expect($optionItem)->not->toBeNull()
        ->and($optionItem->item_kind)->toBe(CommercialItemKind::LensOption);
});

test('revise items preselects the existing lens option', function (): void {
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $option = LensOption::factory()->create(['price' => 850]);
    $quotation = quotationWithLensBuild($lensCategory, $option);

    $this->actingAs($this->staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->mountAction('reviseItems')
        ->assertActionDataSet(function (array $data) use ($option): bool {
            return collect($data['items'] ?? [])->contains(fn (array $item): bool => $item['item_type'] === 'lens_option'
                && (int) $item['lens_option_id'] === $option->id);
        });
});

test('confirmation copies options, snapshots, billing, and no inventory movement', function (): void {
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $option = LensOption::factory()->create([
        'name' => 'Anti-reflective coating',
        'price' => 850,
    ]);
    $quotation = quotationWithLensBuild($lensCategory, $option);
    $quotation->load('patient');

    $result = app(ConfirmQuotationSale::class)->handle($quotation, $this->staff);
    $order = $result['optical_order']->fresh(['items']);
    $optionItem = $order->items->firstWhere('lens_option_id', $option->id);

    expect($optionItem)->not->toBeNull()
        ->and($optionItem->item_kind)->toBe(CommercialItemKind::LensOption)
        ->and($optionItem->item_snapshot)->toBe([
            'lens_option_id' => $option->id,
            'lens_option_name' => 'Anti-reflective coating',
        ])
        ->and((float) $result['billing_record']->total_amount)->toBe(3850.0)
        ->and(BillingRecordItem::query()->where('job_order_item_id', $optionItem->id)->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('job_order_id', $order->id)->count())->toBe(0);
});

test('confirmed option snapshots survive catalog edits', function (): void {
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $option = LensOption::factory()->create(['name' => 'Tint', 'price' => 500]);
    $quotation = quotationWithLensBuild($lensCategory, $option);

    $result = app(ConfirmQuotationSale::class)->handle($quotation, $this->staff);
    $option->update(['name' => 'Renamed tint', 'is_active' => false]);

    $confirmedItem = $result['optical_order']->items()->where('lens_option_id', $option->id)->firstOrFail();

    expect($confirmedItem->item_snapshot['lens_option_name'])->toBe('Tint');
});

test('confirmation retry does not duplicate option lines or billing', function (): void {
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $option = LensOption::factory()->create(['price' => 500]);
    $quotation = quotationWithLensBuild($lensCategory, $option);

    $first = app(ConfirmQuotationSale::class)->handle($quotation, $this->staff);
    $second = app(ConfirmQuotationSale::class)->handle($quotation->fresh(), $this->staff);

    expect($first['optical_order']->id)->toBe($second['optical_order']->id)
        ->and($second['optical_order']->items()->where('lens_option_id', $option->id)->count())->toBe(1)
        ->and(BillingRecordItem::query()->where('job_order_item_id', $second['optical_order']->items()->where('lens_option_id', $option->id)->value('id'))->count())->toBe(1);
});
