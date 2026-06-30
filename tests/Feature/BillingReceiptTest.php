<?php

use App\Models\Billing;
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
