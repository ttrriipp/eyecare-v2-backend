<?php

use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// ── Authentication ─────────────────────────────────────────────────────────

test('unauthenticated access returns 401', function () {
    $this->getJson('/api/v1/eyewear')->assertUnauthorized();
});

test('patient profile absent returns 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear')
        ->assertForbidden();
});

// ── List ───────────────────────────────────────────────────────────────────

test('patient sees only their aggregates', function () {
    $user = User::factory()->patient()->create();
    $other = User::factory()->patient()->create();

    JobOrder::factory()->create(['patient_id' => $user->patient->id, 'status' => JobOrderStatus::Queued]);
    JobOrder::factory()->create(['patient_id' => $other->patient->id, 'status' => JobOrderStatus::Queued]);

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('default filter is current', function () {
    $user = User::factory()->patient()->create();
    JobOrder::factory()->create(['patient_id' => $user->patient->id, 'status' => JobOrderStatus::Queued]);

    $response = $this->actingAs($user)->getJson('/api/v1/eyewear')->assertOk();

    expect($response->json('data.0.progress'))->toBe('in_preparation');
});

test('current filter excludes dispensed', function () {
    $user = User::factory()->patient()->create();
    JobOrder::factory()->create(['patient_id' => $user->patient->id, 'status' => JobOrderStatus::Dispensed]);

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear?filter=current')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('history filter includes dispensed', function () {
    $user = User::factory()->patient()->create();
    JobOrder::factory()->create(['patient_id' => $user->patient->id, 'status' => JobOrderStatus::Dispensed]);

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear?filter=history')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('invalid filter returns 422', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear?filter=invalid')
        ->assertUnprocessable();
});

test('invalid per_page returns 422', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear?per_page=100')
        ->assertUnprocessable();
});

test('pagination envelope matches spec', function () {
    $user = User::factory()->patient()->create();
    JobOrder::factory()->create(['patient_id' => $user->patient->id, 'status' => JobOrderStatus::Queued]);

    $response = $this->actingAs($user)->getJson('/api/v1/eyewear')->assertOk();

    $response->assertJsonStructure([
        'data',
        'links' => ['first', 'last', 'prev', 'next'],
        'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
    ]);
});

test('estimate-only record appears in list', function () {
    $user = User::factory()->patient()->create();
    Quotation::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => QuotationStatus::Presented,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.progress', 'estimate_available');
});

test('linked quotation and job order produce one list entry', function () {
    $user = User::factory()->patient()->create();
    $key = 'eyw_DUPLICATEKEY123456789012345';
    $quotation = Quotation::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => QuotationStatus::Accepted,
        'eyewear_key' => $key,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);
    JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::InProgress,
        'eyewear_key' => $key,
        'total_amount' => 5000,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('dispensed with balance remains in history', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    BillingRecord::factory()->partiallyPaid()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/eyewear?filter=history')
        ->assertOk();

    expect($response->json('data.0.progress'))->toBe('dispensed')
        ->and($response->json('data.0.payment_status'))->toBe('balance_due');
});

test('paid billing produces payment_status paid', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    BillingRecord::factory()->paid()->create(['job_order_id' => $jobOrder->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/eyewear?filter=history')
        ->assertOk();

    expect($response->json('data.0.payment_status'))->toBe('paid');
});

test('description field is present', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Queued,
    ]);
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Classic Rectangle Frame',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/eyewear')->assertOk();

    expect($response->json('data.0.description'))->toBe('Classic Rectangle Frame');
});

// ── Detail ─────────────────────────────────────────────────────────────────

test('canonical key resolves to detail', function () {
    $user = User::factory()->patient()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => QuotationStatus::Presented,
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/eyewear/{$quotation->eyewear_key}")
        ->assertOk()
        ->assertJsonPath('data.key', $quotation->eyewear_key);
});

test('jo_ alias resolves to canonical detail', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/eyewear/jo_{$jobOrder->id}")
        ->assertOk();

    expect($response->json('data.key'))->toStartWith('eyw_');
});

test('another patients key returns 404', function () {
    $user = User::factory()->patient()->create();
    $other = User::factory()->patient()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $other->patient->id,
        'status' => QuotationStatus::Presented,
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/eyewear/{$quotation->eyewear_key}")
        ->assertNotFound();
});

test('another patients alias returns 404', function () {
    $user = User::factory()->patient()->create();
    $other = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $other->patient->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/eyewear/jo_{$jobOrder->id}")
        ->assertNotFound();
});

test('nonexistent key returns 404', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear/eyw_NONEXISTENT1234567890123456')
        ->assertNotFound();
});

test('detail includes estimate section when present', function () {
    $user = User::factory()->patient()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => QuotationStatus::Presented,
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Classic Rectangle Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/eyewear/{$quotation->eyewear_key}")
        ->assertOk();

    $response->assertJsonStructure([
        'data' => ['estimate' => ['quotation_number', 'status', 'items']],
    ]);
});

test('detail omits sections for job-order-only', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/eyewear/{$jobOrder->eyewear_key}")
        ->assertOk();

    $data = $response->json('data');

    expect($data)->not->toHaveKey('estimate')
        ->and($data)->toHaveKey('preparation')
        ->and($data)->not->toHaveKey('dispensing')
        ->and($data)->not->toHaveKey('payment_summary');
});

test('detail includes dispensing for ready job order', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::ReadyForDispensing,
        'ready_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/eyewear/{$jobOrder->eyewear_key}")
        ->assertOk();

    $response->assertJsonStructure([
        'data' => ['dispensing' => ['status', 'ready_at']],
    ]);
});

test('detail omits payment summary for voided billing', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    BillingRecord::factory()->voided()->create(['job_order_id' => $jobOrder->id]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/eyewear/{$jobOrder->eyewear_key}")
        ->assertOk();

    $data = $response->json('data');

    expect($data)->not->toHaveKey('payment_summary')
        ->and($data['payment_status'])->toBeNull()
        ->and($data['balance_due'])->toBeNull();
});

test('only posted payments appear in payment summary', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    $billing = BillingRecord::factory()->partiallyPaid()->create([
        'job_order_id' => $jobOrder->id,
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 3000,
        'payment_method' => 'cash',
        'status' => 'posted',
        'recorded_at' => now(),
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 2000,
        'payment_method' => 'gcash',
        'status' => 'reversed',
        'recorded_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/eyewear/{$jobOrder->eyewear_key}")
        ->assertOk();

    expect($response->json('data.payment_summary.payments'))->toHaveCount(1);
});

test('sensitive fields are absent from detail', function () {
    $user = User::factory()->patient()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => JobOrderStatus::Dispensed,
        'notes' => 'Internal note',
    ]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'notes' => 'Internal billing note',
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/eyewear/{$jobOrder->eyewear_key}")
        ->assertOk();

    $json = json_encode($response->json());

    expect($json)->not->toContain('patient_id')
        ->and($json)->not->toContain('internal_notes')
        ->and($json)->not->toContain('voided_by')
        ->and($json)->not->toContain('void_reason')
        ->and($json)->not->toContain('recorded_by');
});

// ── Routes ─────────────────────────────────────────────────────────────────

test('only GET routes exist for eyewear', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri, 'api/v1/eyewear'))
        ->flatMap(fn ($r) => array_map(fn ($m) => "{$m} /{$r->uri}", (array) $r->methods));

    foreach ($routes as $route) {
        expect($route)->toMatch('/^(GET|HEAD) \//');
    }
});
