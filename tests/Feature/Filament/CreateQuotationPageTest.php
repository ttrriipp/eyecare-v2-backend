<?php

use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Encounter;
use App\Models\LensCategory;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff creates a quotation from an encounter query context', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['encounter' => (string) $encounter->id])
        ->assertFormFieldDoesNotExist('patient_id')
        ->assertFormSet(['include_prescription_eyewear' => true])
        ->fillForm([
            'valid_until' => now()->addWeek()->toDateString(),
            'discount_amount' => 0,
            'notes' => 'Patient-visible estimate note.',
        ]);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set('data.include_prescription_eyewear', false)
        ->set("data.items.{$itemKey}.item_kind", 'custom_product')
        ->set("data.items.{$itemKey}.description", 'Complete frame and single vision lens')
        ->set("data.items.{$itemKey}.quantity", 1)
        ->set("data.items.{$itemKey}.unit_price", 12500)
        ->set('data.notes', 'Patient-visible estimate note.')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $quotation = Quotation::query()->where('encounter_id', $encounter->id)->firstOrFail();

    expect($quotation->patient_id)->toBe($encounter->patient_id)
        ->and($quotation->total)->toBe('12500.00')
        ->and($quotation->notes)->toBe('Patient-visible estimate note.');
});

test('quotation creation is draft-only and does not offer accept and continue', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateQuotation::class)
        ->assertSee('Save Draft')
        ->assertSee('Cancel')
        ->assertSee('Subtotal')
        ->assertSee('Estimated Total')
        ->assertDontSee('Accept & Continue')
        ->assertActionDoesNotExist('acceptAndContinue');
});

test('catalog item details are locked while only frame quantity is fixed', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $product = Product::factory()->create([
        'name' => 'Aster Frame',
        'product_type' => 'frame',
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'name' => 'Matte Black',
        'price' => 2450,
    ]);
    $accessory = Product::factory()->create([
        'name' => 'Lens Cloth',
        'product_type' => 'accessory',
    ]);
    $accessoryVariant = ProductVariant::factory()->create([
        'product_id' => $accessory->id,
        'name' => 'Blue',
        'price' => 150,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['patient' => (string) $patient->id]);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'catalog')
        ->set("data.items.{$itemKey}.product_variant_id", $variant->id)
        ->assertFormSet([
            "items.{$itemKey}.description" => 'Aster Frame — Matte Black',
            "items.{$itemKey}.unit_price" => '2450.00',
            "items.{$itemKey}.quantity" => 1,
        ]);

    $catalogFields = $component->instance()->form->getFlatFields(withHidden: true);
    $descriptionKey = collect(array_keys($catalogFields))
        ->first(fn (string $key): bool => str_ends_with($key, '.description'));
    $unitPriceKey = collect(array_keys($catalogFields))
        ->first(fn (string $key): bool => str_ends_with($key, '.unit_price'));
    $quantityKey = collect(array_keys($catalogFields))
        ->first(fn (string $key): bool => str_ends_with($key, '.quantity'));

    expect($catalogFields[$descriptionKey])->toBeInstanceOf(TextInput::class)
        ->and($catalogFields[$descriptionKey]->isVisible())->toBeFalse()
        ->and($catalogFields[$descriptionKey]->isDisabled())->toBeTrue()
        ->and($catalogFields[$unitPriceKey]->isDisabled())->toBeTrue()
        ->and($catalogFields[$quantityKey]->isDisabled())->toBeTrue();

    $component->set("data.items.{$itemKey}.product_variant_id", $accessoryVariant->id);

    $accessoryFields = $component->instance()->form->getFlatFields(withHidden: true);

    expect($accessoryFields[$descriptionKey]->isVisible())->toBeFalse()
        ->and($accessoryFields[$descriptionKey]->isDisabled())->toBeTrue()
        ->and($accessoryFields[$unitPriceKey]->isDisabled())->toBeTrue()
        ->and($accessoryFields[$quantityKey]->isDisabled())->toBeFalse();

    $component->set("data.items.{$itemKey}.item_kind", 'custom_product');

    $customFields = $component->instance()->form->getFlatFields(withHidden: true);

    expect($customFields[$descriptionKey]->isVisible())->toBeTrue()
        ->and($customFields[$descriptionKey]->isDisabled())->toBeFalse()
        ->and($customFields[$unitPriceKey]->isDisabled())->toBeFalse()
        ->and($customFields[$quantityKey]->isDisabled())->toBeFalse();
});

test('quotation creation stays quiet when no spectacle prescription is linked', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    Livewire::test(CreateQuotation::class, ['patient' => (string) $patient->id])
        ->assertDontSee('No spectacle prescription linked')
        ->assertDontSee('You may quote frames, contact lenses, accessories, custom products, and services.')
        ->assertDontSee('Choose the patient’s current prescription above before confirming a lens package.')
        ->assertDontSee('Catalog price; apply an admin discount in Quotation Details when needed.')
        ->assertDontSee('Describe the uncatalogued item or service.')
        ->assertDontSee('Priced as one pair.')
        ->assertDontSee('A quotation may include one frame.')
        ->set('data.prescription_id', $prescription->id)
        ->assertDontSee('No spectacle prescription linked');
});

test('prescription eyewear mode follows the entry context and controls guided item choices', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    $off = Livewire::test(CreateQuotation::class, ['patient' => (string) $patient->id])
        ->assertFormSet(['include_prescription_eyewear' => false]);

    $offFields = $off->instance()->form->getFlatFields(withHidden: true);
    $offItemKindKey = collect(array_keys($offFields))
        ->first(fn (string $key): bool => str_ends_with($key, '.item_kind'));

    expect($offFields[$offItemKindKey]->getOptions())
        ->toHaveKeys(['catalog', 'service', 'custom_product', 'custom_service']);

    Livewire::test(CreateQuotation::class, ['prescription' => (string) $prescription->id])
        ->assertFormSet(['include_prescription_eyewear' => true])
        ->assertSee('Prescription Eyewear Build')
        ->set('data.include_prescription_eyewear', false)
        ->assertDontSee('Prescription Eyewear Build');
});

test('guided prescription eyewear requires a current prescription and lens package', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['prescription' => (string) $prescription->id]);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'custom_product')
        ->set("data.items.{$itemKey}.description", 'Patient-supplied frame')
        ->set("data.items.{$itemKey}.quantity", 1)
        ->set("data.items.{$itemKey}.unit_price", 1000)
        ->assertFormSet(['include_prescription_eyewear' => true])
        ->call('create')
        ->assertNotified();

    expect(Quotation::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

test('lens selection uses a fixed pair quantity and shows the eyewear build summary', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $lensCategory = LensCategory::factory()->create([
        'name' => 'Single Vision',
        'price' => 1800,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['prescription' => (string) $prescription->id]);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'lens')
        ->set("data.items.{$itemKey}.lens_category_id", $lensCategory->id)
        ->assertFormSet([
            "items.{$itemKey}.description" => 'Single Vision',
            "items.{$itemKey}.unit_price" => '1800.00',
            "items.{$itemKey}.quantity" => 1,
        ])
        ->assertSee('Prescription Eyewear Build')
        ->assertSee('Lens package')
        ->assertSee('Selected');

    $fields = $component->instance()->form->getFlatFields(withHidden: true);
    $unitPriceKey = collect(array_keys($fields))
        ->first(fn (string $key): bool => str_ends_with($key, '.unit_price'));
    $quantityKey = collect(array_keys($fields))
        ->first(fn (string $key): bool => str_ends_with($key, '.quantity'));

    expect($fields[$unitPriceKey]->isDisabled())->toBeTrue()
        ->and($fields[$quantityKey]->isDisabled())->toBeTrue();
});

test('staff creates a quotation from an existing prescription with no new encounter', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['prescription' => (string) $prescription->id])
        ->assertFormFieldDoesNotExist('patient_id');
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'lens')
        ->set("data.items.{$itemKey}.lens_category_id", $lensCategory->id)
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $quotation = Quotation::query()->where('prescription_id', $prescription->id)->firstOrFail();

    expect($quotation->encounter_id)->toBeNull()
        ->and($quotation->patient_id)->toBe($encounter->patient_id);
});

test('an existing prescription cannot be reused for corrective eyewear once superseded', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $original = Prescription::factory()->linkedToEncounter($encounter)->create();
    Prescription::factory()->linkedToEncounter($encounter)->create([
        'previous_prescription_id' => $original->id,
    ]);
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['prescription' => (string) $original->id]);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'lens')
        ->set("data.items.{$itemKey}.lens_category_id", $lensCategory->id)
        ->call('create')
        ->assertNotified();

    expect(Quotation::query()->where('prescription_id', $original->id)->exists())->toBeFalse();
});

test('staff creates a quotation from a patient query context with no encounter', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['patient' => (string) $patient->id])
        ->assertFormFieldDoesNotExist('patient_id');
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'custom_product')
        ->set("data.items.{$itemKey}.description", 'Sunglasses')
        ->set("data.items.{$itemKey}.quantity", 1)
        ->set("data.items.{$itemKey}.unit_price", 2500);

    $component
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $quotation = Quotation::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($quotation->encounter_id)->toBeNull()
        ->and($quotation->total)->toBe('2500.00');
});

test('direct quotation with patient context rejects corrective items without an encounter', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $this->actingAs($staff);

    Livewire::test(CreateQuotation::class, ['patient' => (string) $patient->id])
        ->fillForm([
            'items' => [[
                'item_kind' => 'lens',
                'lens_category_id' => $lensCategory->id,
                'description' => 'Single Vision Lens',
                'quantity' => 1,
                'unit_price' => 1200,
            ]],
        ])
        ->call('create')
        ->assertNotified();

    expect(Quotation::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

test('staff picks a patient manually when no context is provided', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class)
        ->assertFormFieldExists('patient_id')
        ->set('data.patient_id', $patient->id);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'custom_product')
        ->set("data.items.{$itemKey}.description", 'Contact Lens Solution')
        ->set("data.items.{$itemKey}.quantity", 1)
        ->set("data.items.{$itemKey}.unit_price", 400)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Quotation::query()->where('patient_id', $patient->id)->exists())->toBeTrue();
});

test('a manually-picked patient can select their current prescription to add a lens item', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class)
        ->set('data.patient_id', $patient->id)
        ->assertFormFieldExists('prescription_id')
        ->set('data.include_prescription_eyewear', true)
        ->set('data.prescription_id', $prescription->id);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'lens')
        ->set("data.items.{$itemKey}.lens_category_id", $lensCategory->id)
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $quotation = Quotation::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($quotation->prescription_id)->toBe($prescription->id);
});

test('a superseded prescription picked manually is still rejected for a lens item', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $original = Prescription::factory()->create(['patient_id' => $patient->id]);
    Prescription::factory()->create([
        'patient_id' => $patient->id,
        'previous_prescription_id' => $original->id,
    ]);
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'prescription_id' => $original->id,
        ]);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'lens')
        ->set("data.items.{$itemKey}.lens_category_id", $lensCategory->id)
        ->call('create');

    expect(Quotation::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

test('a quotation with only a patient context in the URL still offers a prescription picker for lens items', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['patient' => (string) $patient->id])
        ->set('data.prescription_id', $prescription->id);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.item_kind", 'lens')
        ->set("data.items.{$itemKey}.lens_category_id", $lensCategory->id)
        ->call('create')
        ->assertHasNoFormErrors();

    $quotation = Quotation::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($quotation->prescription_id)->toBe($prescription->id);
});

test('prescription detail links to the create quotation page with the prescription itself', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    $component = Livewire::test(ViewPrescription::class, ['record' => $prescription->getRouteKey()])
        ->assertActionVisible('createQuotation');

    expect($component->instance()->getAction('createQuotation')->getUrl())
        ->toBe(QuotationResource::getUrl('create', ['prescription' => $prescription->id]));
});
