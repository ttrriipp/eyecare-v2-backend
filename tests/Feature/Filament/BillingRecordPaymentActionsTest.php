<?php

use App\Filament\Resources\BillingRecords\Pages\EditBillingRecord;
use App\Filament\Resources\BillingRecords\RelationManagers\PaymentsRelationManager;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('billing payments do not expose the payment correction action', function (): void {
    $admin = User::factory()->admin()->create();
    $billingRecord = BillingRecord::factory()->partiallyPaid()->create();
    $payment = BillingPayment::factory()->create([
        'billing_record_id' => $billingRecord->id,
        'amount' => $billingRecord->amount_paid,
    ]);

    Livewire::actingAs($admin)
        ->test(PaymentsRelationManager::class, [
            'ownerRecord' => $billingRecord,
            'pageClass' => EditBillingRecord::class,
        ])
        ->assertTableColumnDoesNotExist('status')
        ->assertTableActionDoesNotExist(TestAction::make('correctPayment')->table($payment));
});
