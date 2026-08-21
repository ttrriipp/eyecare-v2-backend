<?php

use App\Enums\BillingItemSourceKind;
use App\Filament\Resources\BillingRecords\Pages\EditBillingRecord;
use App\Filament\Resources\BillingRecords\Pages\ListBillingRecords;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('billing presentation uses consultation labels for encounter sources', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->create();
    $billing = BillingRecord::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
    ]);
    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'source_kind' => BillingItemSourceKind::Encounter,
        'encounter_id' => $encounter->id,
        'job_order_item_id' => null,
    ]);

    $this->actingAs($staff);

    $list = Livewire::test(ListBillingRecords::class);
    $columns = $list->instance()->getTable()->getColumns();
    $sourceFilter = $list->instance()->getTable()->getFilters()['source'];

    expect($columns['encounter.encounter_number']->getLabel())->toBe('Consultation')
        ->and($sourceFilter->getOptions()['encounter'])->toBe('Consultation')
        ->and($billing->items->first()->getSourceLabel())->toBe('Consultation');

    Livewire::test(EditBillingRecord::class, ['record' => $billing->getRouteKey()])
        ->assertSee('Consultation')
        ->assertDontSee('Encounter');
});
