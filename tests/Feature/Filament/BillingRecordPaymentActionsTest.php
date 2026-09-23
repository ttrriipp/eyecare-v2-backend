<?php

use App\Filament\Resources\BillingRecords\Pages\EditBillingRecord;
use App\Filament\Resources\BillingRecords\RelationManagers\PaymentsRelationManager;
use App\Models\AccessoryOrderRequest;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
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

test('billing page hides manual payment entry for an accessory order request', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create();
    AccessoryOrderRequest::factory()->accepted()->create([
        'patient_id' => $jobOrder->patient_id,
        'job_order_id' => $jobOrder->id,
    ]);
    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'patient_id' => $jobOrder->patient_id,
    ]);

    $component = Livewire::actingAs($staff)
        ->test(PaymentsRelationManager::class, [
            'ownerRecord' => $billingRecord,
            'pageClass' => EditBillingRecord::class,
        ]);
    $recordPaymentAction = collect($component->instance()->getTable()->getHeaderActions())
        ->first(fn (Action $action): bool => $action->getName() === 'recordPayment');

    expect($recordPaymentAction)
        ->toBeInstanceOf(Action::class)
        ->and($recordPaymentAction->isVisible())->toBeFalse();
});

test('billing page keeps manual payment entry for a normal optical order', function (): void {
    $staff = User::factory()->staff()->create();
    $billingRecord = BillingRecord::factory()->create();

    $component = Livewire::actingAs($staff)
        ->test(PaymentsRelationManager::class, [
            'ownerRecord' => $billingRecord,
            'pageClass' => EditBillingRecord::class,
        ]);
    $recordPaymentAction = collect($component->instance()->getTable()->getHeaderActions())
        ->first(fn (Action $action): bool => $action->getName() === 'recordPayment');

    expect($recordPaymentAction)
        ->toBeInstanceOf(Action::class)
        ->and($recordPaymentAction->isVisible())->toBeTrue();
});

test('billing record payment reference is hidden for cash and shown for non-cash methods', function (): void {
    $staff = User::factory()->staff()->create();
    $billingRecord = BillingRecord::factory()->partiallyPaid()->create();

    Livewire::actingAs($staff)
        ->test(PaymentsRelationManager::class, [
            'ownerRecord' => $billingRecord,
            'pageClass' => EditBillingRecord::class,
        ])
        ->mountTableAction('recordPayment')
        ->assertSchemaComponentExists('payment_method', checkComponentUsing: function (Select $field): bool {
            expect($field->getOptions())->toBe([
                'cash' => 'Cash',
                'gcash' => 'GCash',
                'bank_transfer' => 'Bank Transfer',
            ]);

            return true;
        })
        ->assertSchemaComponentHidden('reference_number')
        ->assertMountedActionModalDontSee('Reference #')
        ->setTableActionData(['payment_method' => 'gcash'])
        ->assertSchemaComponentVisible('reference_number')
        ->assertMountedActionModalSee('Reference #');
});
