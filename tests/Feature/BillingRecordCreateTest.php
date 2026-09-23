<?php

use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\Pages\CreateBillingRecord;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(NotificationStatusSeeder::class);
});

test('staff can create a service-only bill from the bill preview page', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(30),
    ]);
    $service = Service::factory()->create([
        'name' => 'Comprehensive Eye Exam',
        'price' => 800,
    ]);

    $this->actingAs($staff);

    $component = Livewire::test(CreateBillingRecord::class)
        ->assertFormFieldExists('items')
        ->assertFormFieldExists('service_items')
        ->assertFormFieldDoesNotExist('job_order_id');

    expect($component->get('data.items'))->toBeEmpty()
        ->and($component->get('data.service_items'))->toHaveCount(1);

    $component
        ->fillForm([
            'patient_id' => $patient->id,
            'items' => [],
            'service_items' => [[
                'service_source' => 'catalog',
                'service_id' => $service->id,
                'quantity' => 1,
            ]],
            'payment_due_date' => today()->addDays(7)->toDateString(),
            'notes' => 'Pay at the front desk.',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Bill created')
        ->assertRedirect();

    $billingRecord = BillingRecord::query()
        ->where('patient_id', $patient->id)
        ->firstOrFail();
    $billingItem = $billingRecord->items()->firstOrFail();

    expect($billingRecord->job_order_id)->toBeNull()
        ->and($billingRecord->getSourceContext())->toBe('Direct Service')
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Unpaid)
        ->and((float) $billingRecord->total_amount)->toBe(800.0)
        ->and($billingRecord->notes)->toBe('Pay at the front desk.')
        ->and($billingItem->source_kind)->toBe(BillingItemSourceKind::DirectService)
        ->and($billingItem->service_id)->toBe($service->id)
        ->and($billingItem->description)->toBe('Comprehensive Eye Exam')
        ->and((float) $billingItem->unit_price)->toBe(800.0);
});

test('an administrator can create a bill with an optical order and services', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(30),
    ]);
    $variant = ProductVariant::factory()->create([
        'price' => 1200,
        'stock_quantity' => 5,
    ]);
    $service = Service::factory()->create([
        'name' => 'Lens Fitting',
        'price' => 500,
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateBillingRecord::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'items' => [[
                'item_kind' => 'catalog',
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ]],
            'service_items' => [[
                'service_source' => 'catalog',
                'service_id' => $service->id,
                'quantity' => 1,
            ]],
            'discount_type' => 'other',
            'discount_amount' => 100,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Bill created')
        ->assertRedirect();

    $billingRecord = BillingRecord::query()
        ->where('patient_id', $patient->id)
        ->firstOrFail();
    $jobOrder = $billingRecord->jobOrder;
    $jobOrderItem = $jobOrder->items()->firstOrFail();
    $items = $billingRecord->items()->get();

    expect($jobOrder)->toBeInstanceOf(JobOrder::class)
        ->and($jobOrderItem->product_variant_id)->toBe($variant->id)
        ->and($items)->toHaveCount(2)
        ->and($items->firstWhere('job_order_item_id', $jobOrderItem->id)->source_kind)
        ->toBe(BillingItemSourceKind::OpticalOrder)
        ->and($items->firstWhere('service_id', $service->id)->source_kind)
        ->toBe(BillingItemSourceKind::DirectService)
        ->and((float) $billingRecord->subtotal_amount)->toBe(1700.0)
        ->and((float) $billingRecord->discount_amount)->toBe(100.0)
        ->and((float) $billingRecord->total_amount)->toBe(1600.0);
});

test('an administrator can fully discount a bill and it is marked as paid', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(30),
    ]);
    $service = Service::factory()->create([
        'name' => 'Comprehensive Eye Exam',
        'price' => 800,
    ]);

    $this->actingAs($admin);

    Livewire::test(CreateBillingRecord::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'items' => [],
            'service_items' => [[
                'service_source' => 'catalog',
                'service_id' => $service->id,
                'quantity' => 1,
            ]],
            'discount_type' => 'other',
            'discount_amount' => 800,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Bill created')
        ->assertRedirect();

    $billingRecord = BillingRecord::query()
        ->where('patient_id', $patient->id)
        ->firstOrFail();

    expect($billingRecord->status)->toBe(BillingRecordStatus::Paid)
        ->and((float) $billingRecord->total_amount)->toBe(0.0)
        ->and((float) $billingRecord->balance_due)->toBe(0.0);
});

test('bill preview requires an optical order or service line', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateBillingRecord::class)
        ->fillForm([
            'patient_id' => $patient->id,
            'items' => [],
            'service_items' => [[]],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(BillingRecord::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});
