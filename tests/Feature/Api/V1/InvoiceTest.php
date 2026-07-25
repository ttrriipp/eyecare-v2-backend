<?php

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patient can list their own invoices', function () {
    $user = User::factory()->patient()->create();
    $invoices = Invoice::factory()->count(2)->create(['patient_id' => $user->patient->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/invoices')
        ->assertOk();

    expect(count($response->json('data')))->toBe(2);
});

test('patient can view their own invoice with items and payments', function () {
    $user = User::factory()->patient()->create();
    $invoice = Invoice::factory()->create([
        'patient_id' => $user->patient->id,
        'total' => 5000,
        'balance_due' => 3000,
    ]);

    InvoicePayment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 2000,
        'status' => 'posted',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/invoices/{$invoice->id}")
        ->assertOk()
        ->assertJsonPath('data.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonCount(1, 'data.payments');
});

test('patient cannot view another patients invoice', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $invoice = Invoice::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson("/api/v1/invoices/{$invoice->id}")
        ->assertNotFound();
});

test('invoices require authentication', function () {
    $this->getJson('/api/v1/invoices')->assertUnauthorized();
});

test('patient sees only posted payments not voided', function () {
    $user = User::factory()->patient()->create();
    $invoice = Invoice::factory()->create(['patient_id' => $user->patient->id]);

    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000, 'status' => 'posted']);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 500, 'status' => 'voided']);

    $this->actingAs($user)
        ->getJson("/api/v1/invoices/{$invoice->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.payments');
});
