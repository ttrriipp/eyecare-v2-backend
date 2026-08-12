<?php

use App\Actions\Quotations\RecordQuotationDecision;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
});

test('draft can be directly accepted (direct sale)', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $this->staff);

    expect($result->status)->toBe(QuotationStatus::Accepted)
        ->and($result->confirmed_by)->toBe($this->staff->id)
        ->and($result->confirmed_at)->not->toBeNull();
});

test('draft can be declined', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'declined', $this->staff, reason: 'Patient changed mind');

    expect($result->status)->toBe(QuotationStatus::Declined);
});

test('accepted quotation is terminal', function () {
    $quotation = Quotation::factory()->accepted()->create();

    app(RecordQuotationDecision::class)->handle($quotation, 'declined', $this->staff);
})->throws(ValidationException::class);

test('declined quotation is terminal', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);

    app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $this->staff);
})->throws(ValidationException::class);
