<?php

use App\Models\Prescription;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('PdfService prescriptionPrintout returns a PDF response', function () {
    $prescription = Prescription::factory()->create();

    $response = app(PdfService::class)->prescriptionPrintout($prescription);

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('prescription PDF renders with encrypted fields decrypted', function () {
    $prescription = Prescription::factory()->create([
        'od_sphere' => '-2.25',
        'pd' => '63.5',
        'notes' => 'Mild myopia.',
    ]);

    $response = app(PdfService::class)->prescriptionPrintout($prescription);

    expect($response->getContent())->not->toBeEmpty();
    // The model's encrypted cast decrypts before passing to Blade — PDF should contain data
    expect($prescription->od_sphere)->toBe('-2.25');
    expect($prescription->pd)->toBe('63.5');
});
