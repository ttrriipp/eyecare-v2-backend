<?php

use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\LensType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VisitReason;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
    $this->customer = User::factory()->customer()->create();
    $this->staff = User::factory()->staff()->create();
    $this->admin = User::factory()->admin()->create();
});

// ─── New appointment booking ──────────────────────────────────────────────────

test('all staff and admin are notified when a customer books an appointment', function () {
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->addDay()->toISOString(),
        ])
        ->assertCreated();

    expect($this->staff->notifications()->count())->toBe(1)
        ->and($this->admin->notifications()->count())->toBe(1);
});

test('new booking notification has correct type and action url', function () {
    $visitReason = VisitReason::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/appointments', [
            'visit_reason_id' => $visitReason->id,
            'scheduled_at' => now()->addDay()->toISOString(),
        ])
        ->assertCreated();

    $notification = $this->staff->notifications()->first();
    expect($notification->data['title'])->toBe('New Appointment Booked');
});

// ─── New order request ────────────────────────────────────────────────────────

test('all staff and admin are notified when a customer submits an order', function () {
    $product = Product::factory()
        ->has(ProductVariant::factory()->count(1), 'variants')
        ->create();
    $variant = $product->variants->first();
    $lensType = LensType::factory()->create();

    $this->actingAs($this->customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [[
                'product_variant_id' => $variant->id,
                'lens_type_id' => $lensType->id,
                'quantity' => 1,
            ]],
        ])
        ->assertCreated();

    expect($this->staff->notifications()->count())->toBe(1)
        ->and($this->admin->notifications()->count())->toBe(1);
});

// ─── New message received ─────────────────────────────────────────────────────

test('all staff are notified when a customer sends a message and no staff is assigned', function () {
    $conversation = Conversation::factory()->create(['customer_id' => $this->customer->id]);

    $this->actingAs($this->customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Hello, I need help.',
        ])
        ->assertCreated();

    expect($this->staff->notifications()->count())->toBe(1)
        ->and($this->admin->notifications()->count())->toBe(1);
});

test('only the assigned staff member is notified when a customer sends a message', function () {
    $assignedStaff = User::factory()->staff()->create();
    $otherStaff = User::factory()->staff()->create();

    Appointment::factory()->create([
        'customer_id' => $this->customer->id,
        'staff_id' => $assignedStaff->id,
    ]);

    $conversation = Conversation::factory()->create(['customer_id' => $this->customer->id]);

    $this->actingAs($this->customer, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Hello again.',
        ])
        ->assertCreated();

    expect($assignedStaff->notifications()->count())->toBe(1)
        ->and($otherStaff->notifications()->count())->toBe(0);
});

test('staff sending a message does not trigger a staff notification', function () {
    $conversation = Conversation::factory()->create(['customer_id' => $this->customer->id]);

    $this->actingAs($this->staff, 'sanctum')
        ->postJson("/api/conversations/{$conversation->id}/messages", [
            'body' => 'Staff reply here.',
        ])
        ->assertCreated();

    expect($this->staff->notifications()->count())->toBe(0)
        ->and($this->admin->notifications()->count())->toBe(0);
});
