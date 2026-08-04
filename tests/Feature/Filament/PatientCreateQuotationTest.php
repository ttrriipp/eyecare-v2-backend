<?php

use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('create quotation action on the patient record links to the create page with patient context', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    $component = Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->assertActionVisible('createQuotation');

    expect($component->instance()->getAction('createQuotation')->getUrl())
        ->toBe(QuotationResource::getUrl('create', ['patient' => $patient->id]));
});
