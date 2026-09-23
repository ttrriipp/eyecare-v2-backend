<?php

use App\Actions\OpticalOrders\CreateOpticalOrder as CreateOpticalOrderAction;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\OpticalOrders\Pages\CreateOpticalOrder;
use App\Filament\Resources\OpticalOrders\Pages\EditOpticalOrder;
use App\Filament\Resources\OpticalOrders\Pages\ListOpticalOrders;
use App\Models\AccessoryOrderRequest;
use App\Models\BillingRecord;
use App\Models\InventoryLot;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\OrderPaymentProof;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(NotificationStatusSeeder::class);
});

test('initial payment method options exclude card payments', function (): void {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateOpticalOrder::class)
        ->assertSchemaComponentExists('deposit_payment_method', checkComponentUsing: function (Select $field): bool {
            expect($field->getOptions())->toBe([
                'cash' => 'Cash',
                'gcash' => 'GCash',
                'bank_transfer' => 'Bank Transfer',
            ]);

            return true;
        });
});

test('direct order discount input uses spinner-free decimal styling', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateOpticalOrder::class)
        ->assertSchemaComponentExists(
            'discount_amount',
            checkComponentUsing: function (TextInput $field): bool {
                expect($field->getMinValue())
                    ->toBe(0)
                    ->and($field->getStep())
                    ->toBe(0.01)
                    ->and($field->getExtraInputAttributes())
                    ->toMatchArray(['class' => 'price-input']);

                return true;
            },
        );
});

test('discount selector offers the Philippine statutory choices and an admin custom option', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Livewire::test(CreateOpticalOrder::class)
        ->assertSchemaComponentExists(
            'discount_type',
            checkComponentUsing: function (Select $field): bool {
                expect($field->canSelectPlaceholder())->toBeFalse()
                    ->and($field->getDefaultState())->toBe('none')
                    ->and($field->getOptions())->toBe([
                        'none' => 'No discount',
                        'senior_citizen' => 'Senior Citizen (20%)',
                        'pwd' => 'PWD (20%)',
                        'other' => 'Other (admin custom)',
                    ]);

                return true;
            },
        );
});

test('direct order items use the service-style source selector layout', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateOpticalOrder::class);
    $itemSource = collect($component->instance()->form->getFlatFields(withHidden: true))
        ->first(fn (Field $field, string $key): bool => str_ends_with($key, '.item_kind'));

    expect($itemSource)
        ->toBeInstanceOf(Radio::class)
        ->and($itemSource->getLabel())
        ->toBe('Item source')
        ->and($itemSource->isInline())
        ->toBeTrue()
        ->and($itemSource->getOptions())
        ->toBe([
            'catalog' => 'Catalog item',
            'custom' => 'Custom item',
        ]);
});

test('selecting a patient aged 60 automatically selects the senior citizen discount', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(60),
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateOpticalOrder::class)
        ->set('data.patient_id', $patient->id)
        ->assertSet('data.discount_type', 'senior_citizen');
});

test('a preselected patient aged 60 automatically receives the senior citizen discount', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(60),
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateOpticalOrder::class, ['patient' => (string) $patient->id])
        ->assertSet('data.discount_type', 'senior_citizen');
});

test('selecting a patient younger than 60 leaves the automatic discount unset', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(59),
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateOpticalOrder::class)
        ->set('data.patient_id', $patient->id)
        ->assertSet('data.discount_type', 'none');
});

test('senior citizen discount is locked for an age-eligible patient', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(60),
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateOpticalOrder::class)
        ->set('data.patient_id', $patient->id)
        ->assertSchemaComponentExists(
            'discount_type',
            checkComponentUsing: function (Select $field): bool {
                expect($field->isDisabled())->toBeTrue();

                return true;
            },
        );
});

test('senior citizen discount is unavailable for a patient under 60', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(59),
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateOpticalOrder::class)
        ->set('data.patient_id', $patient->id)
        ->assertSchemaComponentExists(
            'discount_type',
            checkComponentUsing: function (Select $field): bool {
                expect($field->isDisabled())->toBeFalse()
                    ->and($field->getEnabledOptions())->not->toHaveKey('senior_citizen');

                return true;
            },
        );
});

test('admin statutory discounts apply twenty percent of the order subtotal', function (string $discountType, ?int $patientAge) {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => $patientAge === null ? null : today()->subYears($patientAge),
    ]);
    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 10,
        'price' => 2500,
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateOpticalOrder::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'prepared',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ]],
            'discount_type' => $discountType,
            'discount_amount' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created');

    $billing = JobOrder::query()
        ->where('patient_id', $patient->id)
        ->firstOrFail()
        ->billingRecord;

    expect((float) $billing->discount_amount)->toBe(500.0)
        ->and((float) $billing->total_amount)->toBe(2000.0);
})->with([
    'senior citizen' => ['senior_citizen', 60],
    'pwd' => ['pwd', null],
]);

test('admin can use the other discount option with a custom amount', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 10,
        'price' => 2500,
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateOpticalOrder::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'prepared',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ]],
            'discount_type' => 'other',
            'discount_amount' => 300,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created');

    $billing = JobOrder::query()
        ->where('patient_id', $patient->id)
        ->firstOrFail()
        ->billingRecord;

    expect((float) $billing->discount_amount)->toBe(300.0)
        ->and((float) $billing->total_amount)->toBe(2200.0);
});

test('only administrators can apply a non-zero discount', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);

    $action = app(CreateOpticalOrderAction::class);

    $action->handle(
        patient: $patient,
        creator: $staff,
        items: [[
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 2500,
            'product_variant_id' => $variant->id,
        ]],
        discountAmount: 500,
    );
})->throws(ValidationException::class, 'Only an administrator can apply a discount.');

test('custom items explain which products can be entered manually', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(CreateOpticalOrder::class)
        ->fillForm([
            'items' => [[
                'item_kind' => 'custom',
            ]],
        ])
        ->assertSee('product not listed in the catalog')
        ->assertSee('special-order frame')
        ->assertSee('replacement nose pads');
});

test('selecting a patient preserves a selected catalog frame and its price', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'name' => 'Aster Frame',
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'name' => 'Matte Black',
        'price' => 2450,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(CreateOpticalOrder::class);
    $itemKey = array_key_first($component->get('data.items'));

    $component
        ->set("data.items.{$itemKey}.product_variant_id", $variant->id)
        ->assertFormSet([
            "items.{$itemKey}.description" => 'Aster Frame — Matte Black',
            "items.{$itemKey}.unit_price" => '2450.00',
            "items.{$itemKey}.line_total" => '2,450.00',
        ]);

    $quantityField = collect($component->instance()->form->getFlatFields(withHidden: true))
        ->first(fn (Field $field, string $key): bool => str_ends_with($key, '.quantity'));

    expect($quantityField?->isDisabled())->toBeFalse();

    $component
        ->set("data.items.{$itemKey}.quantity", 2)
        ->assertFormSet([
            "items.{$itemKey}.quantity" => 2,
            "items.{$itemKey}.line_total" => '4,900.00',
        ])
        ->set('data.patient_id', $patient->id)
        ->assertFormSet([
            "items.{$itemKey}.product_variant_id" => $variant->id,
            "items.{$itemKey}.description" => 'Aster Frame — Matte Black',
            "items.{$itemKey}.unit_price" => '2450.00',
            "items.{$itemKey}.line_total" => '4,900.00',
            "items.{$itemKey}.quantity" => 2,
        ]);

    $quantityField = collect($component->instance()->form->getFlatFields(withHidden: true))
        ->first(fn (Field $field, string $key): bool => str_ends_with($key, '.quantity'));

    expect($quantityField?->isDisabled())->toBeFalse();
});

test('staff creates a direct order from the optical orders list', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 1500]);

    $this->actingAs($staff);

    Livewire::test(ListOpticalOrders::class)
        ->assertActionVisible('newDirectOrder')
        ->assertActionHasIcon('newDirectOrder', 'heroicon-o-plus-circle')
        ->assertActionHasUrl('newDirectOrder', OpticalOrderResource::getUrl('create'))
        ->assertTableActionDoesNotExist('newDirectOrder');

    Livewire::test(CreateOpticalOrder::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'prepared',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created')
        ->assertRedirect();

    $jobOrder = JobOrder::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($jobOrder->quotation_id)->toBeNull()
        ->and((float) $jobOrder->total_amount)->toBe(3000.0)
        ->and((int) $jobOrder->items()->firstOrFail()->quantity)->toBe(2);

    expect(OpticalOrderResource::getUrl('edit', ['record' => $jobOrder]))
        ->toContain("/optical-orders/{$jobOrder->id}/edit");
});

test('optical order list exposes payment lifecycle tabs and status labels', function () {
    $staff = User::factory()->staff()->create();
    $awaitingPayment = JobOrder::factory()->create(['status' => JobOrderStatus::PendingPayment]);
    $paymentReview = JobOrder::factory()->create(['status' => JobOrderStatus::PaymentReview]);
    $confirmed = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $preparing = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);

    $this->actingAs($staff);

    $component = Livewire::test(ListOpticalOrders::class);
    $tabs = $component->instance()->getTabs();

    expect($tabs)
        ->toHaveKeys(['awaiting_payment', 'payment_review', 'confirmed']);

    $component
        ->assertTableColumnFormattedStateSet('status', 'Awaiting Payment', record: $awaitingPayment)
        ->assertTableColumnFormattedStateSet('status', 'Payment Review', record: $paymentReview)
        ->assertTableColumnFormattedStateSet('status', 'Confirmed', record: $confirmed)
        ->assertTableColumnFormattedStateSet('status', 'Preparing', record: $preparing)
        ->assertSee('Preparing')
        ->assertDontSee('Processing')
        ->assertDontSee('In Progress')
        ->set('activeTab', 'awaiting_payment')
        ->assertCanSeeTableRecords([$awaitingPayment])
        ->assertCanNotSeeTableRecords([$paymentReview, $confirmed])
        ->set('activeTab', 'payment_review')
        ->assertCanSeeTableRecords([$paymentReview])
        ->assertCanNotSeeTableRecords([$awaitingPayment, $confirmed])
        ->set('activeTab', 'production')
        ->assertCanSeeTableRecords([$preparing])
        ->assertCanNotSeeTableRecords([$awaitingPayment, $paymentReview, $confirmed]);
});

test('optical order start action uses preparation wording', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertActionVisible('start')
        ->assertSee('Start Preparing')
        ->assertDontSee('Start Processing')
        ->mountAction('start')
        ->assertActionMounted('start')
        ->assertMountedActionModalSee('Begin preparing this optical order.');
});

test('dispense modal enables balance release override when a balance remains', function () {
    $admin = User::factory()->admin()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 10000,
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 10000,
        'amount_paid' => 5000,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->mountAction('dispense')
        ->assertActionDataSet(['admin_override' => true]);
});

test('optical order timeline hides milestones that have not been reached', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'started_at' => null,
        'ready_at' => null,
        'dispensed_at' => null,
        'cancelled_at' => null,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSchemaComponentVisible('created_at')
        ->assertSchemaComponentHidden('started_at')
        ->assertSchemaComponentHidden('ready_at')
        ->assertSchemaComponentHidden('dispensed_at')
        ->assertSchemaComponentHidden('cancelled_at');
});

test('mark ready confirmation does not ask for the supplier invoice number again', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'uses_external_supplier' => true,
        'supplier_invoice_number' => 'SUP-INV-2001',
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()]);

    $component
        ->assertSchemaComponentExists('supplier_invoice_number')
        ->mountAction('markReady')
        ->assertSchemaComponentDoesNotExist('supplier_invoice_number')
        ->assertMountedActionModalDontSee('Supplier Invoice Number')
        ->callMountedAction()
        ->assertNotified('Order marked ready');

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::ReadyForDispensing)
        ->and($jobOrder->fresh()->supplier_invoice_number)->toBe('SUP-INV-2001');
});

test('optical order details show a submitted payment proof preview and download action', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::PaymentReview,
    ]);
    $proof = OrderPaymentProof::factory()->create([
        'job_order_id' => $jobOrder->id,
        'mime_type' => 'image/jpeg',
    ]);
    $previewUrl = route('payment-proofs.preview', ['proof' => $proof]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSchemaComponentVisible('payment_proof_preview')
        ->assertSee($previewUrl, false)
        ->assertSee('Submitted payment proof')
        ->assertActionVisible('downloadProof');
});

test('optical order details hide payment proof preview when none has been submitted', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::PendingPayment,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSchemaComponentHidden('payment_proof_preview')
        ->assertActionHidden('downloadProof');
});

test('optical order timeline shows each milestone after its timestamp is recorded', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Cancelled,
        'started_at' => now()->subHours(3),
        'ready_at' => now()->subHours(2),
        'dispensed_at' => now()->subHour(),
        'cancelled_at' => now(),
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSchemaComponentVisible('started_at')
        ->assertSchemaComponentVisible('ready_at')
        ->assertSchemaComponentVisible('dispensed_at')
        ->assertSchemaComponentVisible('cancelled_at');
});

test('staff cannot manually record payment for an accepted accessory order request', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
    ]);
    AccessoryOrderRequest::factory()->accepted()->create([
        'patient_id' => $jobOrder->patient_id,
        'job_order_id' => $jobOrder->id,
    ]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'patient_id' => $jobOrder->patient_id,
        'status' => BillingRecordStatus::Unpaid,
        'balance_due' => 1000,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertActionHidden('recordPayment');
});

test('staff can manually record payment for a normal optical order', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
    ]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'patient_id' => $jobOrder->patient_id,
        'status' => BillingRecordStatus::Unpaid,
        'balance_due' => 1000,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertActionVisible('recordPayment');
});

test('optical order payment reference is hidden for cash and shown for non-cash methods', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
    ]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'patient_id' => $jobOrder->patient_id,
        'status' => BillingRecordStatus::Unpaid,
        'balance_due' => 1000,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->mountAction('recordPayment')
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
        ->setActionData(['payment_method' => 'gcash'])
        ->assertSchemaComponentVisible('reference_number')
        ->assertMountedActionModalSee('Reference #');
});

test('payment proof rejection offers preset reasons and saves the selected reason', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::PaymentReview,
    ]);
    $proof = OrderPaymentProof::factory()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->mountAction('rejectProof')
        ->assertSchemaComponentExists('reason_category', checkComponentUsing: function (Select $field): bool {
            expect($field->getOptions())->toBe([
                'unreadable' => 'The payment proof is blurry or unreadable.',
                'amount_mismatch' => 'The payment amount does not match the order total.',
                'reference_unverified' => 'The payment reference could not be verified.',
                'payment_not_received' => 'The payment could not be verified as received.',
                'other' => 'Other',
            ]);

            return true;
        });

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->callAction('rejectProof', ['reason_category' => 'amount_mismatch'])
        ->assertNotified('Payment rejected, order cancelled');

    expect($proof->fresh()->rejection_reason)
        ->toBe('The payment amount does not match the order total.');
});

test('payment proof rejection requires custom details for other', function (): void {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::PaymentReview,
    ]);
    $proof = OrderPaymentProof::factory()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->callAction('rejectProof', ['reason_category' => 'other'])
        ->assertHasActionErrors(['rejection_details']);

    expect($proof->fresh()->status->value)->toBe('pending');
});

test('immediate checkout paid in full is dispensed with a zero balance', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 1200]);

    $this->actingAs($staff);

    Livewire::test(CreateOpticalOrder::class)
        ->fillForm([
            'fulfillment_mode' => 'immediate',
        ])
        ->assertFormFieldDoesNotExist('recipient_name')
        ->fillForm([
            'patient_id' => $patient->id,
            'fulfillment_mode' => 'immediate',
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ]],
            'deposit_amount' => 1200,
            'deposit_payment_method' => 'cash',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created');

    $jobOrder = JobOrder::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($jobOrder->status->value)->toBe('dispensed')
        ->and($jobOrder->billingRecord->status)->toBe(BillingRecordStatus::Paid)
        ->and((float) $jobOrder->billingRecord->balance_due)->toBe(0.0)
        ->and($jobOrder->dispensingEvents()->latest()->value('recipient_name'))->toBe($patient->full_name);
});

test('new direct order action requires at least one item', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateOpticalOrder::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'items' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['items' => 'min']);

    expect(JobOrder::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

test('direct order page reveals the dedicated eyewear builder for a selected prescription', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    Livewire::test(CreateOpticalOrder::class, [
        'patient' => (string) $patient->id,
        'prescription' => (string) $prescription->id,
    ])
        ->assertSuccessful()
        ->assertSee([
            'New Optical Order',
            'Include prescription eyewear',
            'Prescription Eyewear',
            'Other Items',
            'Preparation Method',
            'Payment',
            'Create Order & Billing',
        ])
        ->assertDontSee('Processing Method')
        ->assertFormFieldExists('eyewear_frame_source')
        ->assertFormFieldExists('eyewear_lens_category_id')
        ->assertFormFieldExists('eyewear_lens_options');
});

test('staff creates a direct prescription eyewear order with other items and payment', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $frame = Product::factory()->create(['product_type' => 'frame', 'name' => 'Aster Frame']);
    $frameVariant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'name' => 'Matte Black',
        'sku' => 'FRM-AST-BLK',
        'price' => 2450,
        'stock_quantity' => 10,
    ]);
    $accessory = Product::factory()->accessory()->create(['name' => 'Cleaning Kit']);
    $accessoryVariant = ProductVariant::factory()->create([
        'product_id' => $accessory->id,
        'price' => 250,
        'stock_quantity' => 10,
    ]);
    InventoryLot::factory()->for($accessoryVariant, 'variant')->create([
        'lot_number' => 'CLEAN-001',
        'expires_on' => now()->addMonths(6)->endOfMonth()->toDateString(),
        'received_quantity' => 10,
        'quantity_on_hand' => 10,
    ]);
    $lensCategory = LensCategory::factory()->withPrice(1800)->create();
    $lensOption = LensOption::factory()->create(['price' => 600]);

    $this->actingAs($staff);

    Livewire::test(CreateOpticalOrder::class, [
        'patient' => (string) $patient->id,
        'prescription' => (string) $prescription->id,
    ])
        ->fillForm([
            'eyewear_frame_source' => 'catalog',
            'eyewear_frame_variant_id' => $frameVariant->id,
            'eyewear_lens_category_id' => $lensCategory->id,
            'eyewear_lens_options' => [['lens_option_id' => $lensOption->id]],
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $accessoryVariant->id,
                'quantity' => 1,
            ]],
            'fulfillment_mode' => 'prepared',
            'uses_external_supplier' => true,
            'deposit_amount' => 5100,
            'deposit_payment_method' => 'cash',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Order created')
        ->assertRedirect();

    $order = JobOrder::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($order->prescription_id)->toBe($prescription->id)
        ->and($order->fulfillment_mode)->toBe('prepared')
        ->and($order->uses_external_supplier)->toBeTrue()
        ->and($order->items)->toHaveCount(4)
        ->and($order->billingRecord)->not->toBeNull()
        ->and((float) $order->billingRecord->balance_due)->toBe(0.0);
});
