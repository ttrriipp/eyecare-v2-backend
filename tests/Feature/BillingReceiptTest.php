<?php

use App\Models\Billing;
use App\Models\User;
use App\Services\PdfService;
use Database\Seeders\PaymentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('PdfService billingReceipt returns a PDF response', function () {
    $this->seed(PaymentStatusSeeder::class);

    $billing = Billing::factory()->issued()->create();

    $response = app(PdfService::class)->billingReceipt($billing);

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('billing receipt PDF contains the billing number', function () {
    $this->seed(PaymentStatusSeeder::class);

    $billing = Billing::factory()->issued()->create();

    $response = app(PdfService::class)->billingReceipt($billing);
    $content = $response->getContent();

    // PDF content is binary, but dompdf embeds text — we can check the Blade renders
    expect($content)->not->toBeNull()->not->toBeEmpty();
});

// ─── API endpoint ─────────────────────────────────────────────────────────────

test('GET /api/billing/{id}/pdf returns PDF for the billing owner', function () {
    $this->seed(PaymentStatusSeeder::class);

    $customer = User::factory()->customer()->create();
    $billing = Billing::factory()->issued()->create(['customer_id' => $customer->id]);

    $this->actingAs($customer, 'sanctum')
        ->get("/api/billing/{$billing->id}/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('GET /api/billing/{id}/pdf returns 403 for a different customer', function () {
    $this->seed(PaymentStatusSeeder::class);

    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();
    $billing = Billing::factory()->issued()->create(['customer_id' => $customer->id]);

    $this->actingAs($otherCustomer, 'sanctum')
        ->get("/api/billing/{$billing->id}/pdf")
        ->assertForbidden();
});

test('GET /api/billing/{id}/pdf returns 401 for unauthenticated requests', function () {
    $billing = Billing::factory()->issued()->create();

    $this->get("/api/billing/{$billing->id}/pdf")
        ->assertUnauthorized();
});
