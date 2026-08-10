<?php

use App\Filament\Resources\LensOptions\LensOptionResource;
use App\Filament\Resources\LensOptions\Pages\CreateLensOption;
use App\Filament\Resources\LensOptions\Pages\EditLensOption;
use App\Filament\Resources\LensOptions\Pages\ListLensOptions;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('an admin can list, create, and edit a lens option', function (): void {
    $admin = User::factory()->admin()->create();
    $option = LensOption::factory()->create([
        'name' => 'Anti-reflective coating',
        'price' => 850,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListLensOptions::class)
        ->assertCanSeeTableRecords([$option]);

    Livewire::test(CreateLensOption::class)
        ->fillForm([
            'name' => 'Photochromic treatment',
            'description' => 'Darkens in sunlight.',
            'price' => 1250,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = LensOption::query()->where('name', 'Photochromic treatment')->firstOrFail();

    Livewire::test(EditLensOption::class, ['record' => $created->getRouteKey()])
        ->fillForm([
            'name' => 'Photochromic treatment',
            'price' => 1400,
            'is_active' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($created->fresh()->price)->toEqualWithDelta(1400, 0.001)
        ->and($created->fresh()->is_active)->toBeFalse();
});

test('non-admin users cannot manage lens options', function (): void {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)
        ->get(LensOptionResource::getUrl('index'))
        ->assertForbidden();
});

test('lens option names are unique', function (): void {
    $admin = User::factory()->admin()->create();
    LensOption::factory()->create(['name' => 'Tint']);

    $this->actingAs($admin);

    Livewire::test(CreateLensOption::class)
        ->fillForm([
            'name' => 'Tint',
            'price' => 500,
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

test('quotation lens option selector lists active options and prefills the line', function (): void {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $active = LensOption::factory()->create([
        'name' => 'Anti-reflective coating',
        'price' => 850,
    ]);
    $inactive = LensOption::factory()->inactive()->create([
        'name' => 'Inactive treatment',
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(CreateQuotation::class, ['patient' => (string) $patient->id]);
    $itemKey = array_key_first($component->get('data.items'));
    $fieldKey = collect(array_keys($component->instance()->form->getFlatFields(withHidden: true)))
        ->first(fn (string $key): bool => str_ends_with($key, '.lens_option_id'));
    $field = $component->instance()->form->getFlatFields(withHidden: true)[$fieldKey] ?? null;

    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->getOptions())->toBe([$active->id => 'Anti-reflective coating']);

    $component
        ->set("data.items.{$itemKey}.item_type", 'lens_option')
        ->set("data.items.{$itemKey}.lens_option_id", $active->id)
        ->assertFormSet([
            "items.{$itemKey}.description" => 'Anti-reflective coating',
            "items.{$itemKey}.unit_price" => '850.00',
        ]);

    expect($inactive->fresh()->is_active)->toBeFalse();
});
