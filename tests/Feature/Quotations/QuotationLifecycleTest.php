<?php

use App\Actions\Quotations\PresentQuotation;
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

test('draft can be presented', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    $result = app(PresentQuotation::class)->handle($quotation, $this->staff);

    expect($result->status)->toBe(QuotationStatus::Presented)
        ->and($result->presented_by)->toBe($this->staff->id)
        ->and($result->presented_at)->not->toBeNull();
});

test('only draft can be presented', function () {
    $quotation = Quotation::factory()->presented()->create();

    app(PresentQuotation::class)->handle($quotation, $this->staff);
})->throws(ValidationException::class, 'Only draft quotations can be presented.');

test('draft can be directly accepted (direct sale)', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $this->staff);

    expect($result->status)->toBe(QuotationStatus::Accepted)
        ->and($result->confirmed_by)->toBe($this->staff->id)
        ->and($result->confirmed_at)->not->toBeNull();
});

test('presented can be accepted', function () {
    $quotation = Quotation::factory()->presented()->create();

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $this->staff);

    expect($result->status)->toBe(QuotationStatus::Accepted)
        ->and($result->confirmed_by)->toBe($this->staff->id);
});

test('presented can be declined', function () {
    $quotation = Quotation::factory()->presented()->create();

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'declined', $this->staff);

    expect($result->status)->toBe(QuotationStatus::Declined);
});

test('presented can be expired', function () {
    $quotation = Quotation::factory()->presented()->create();

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'expired', $this->staff);

    expect($result->status)->toBe(QuotationStatus::Expired);
});

test('presented cannot be returned to draft via decision', function () {
    $quotation = Quotation::factory()->presented()->create();

    app(RecordQuotationDecision::class)->handle($quotation, 'presented', $this->staff);
})->throws(ValidationException::class);

test('accepted quotation is terminal', function () {
    $quotation = Quotation::factory()->accepted()->create();

    app(RecordQuotationDecision::class)->handle($quotation, 'declined', $this->staff);
})->throws(ValidationException::class);

test('declined quotation is terminal', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);

    app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $this->staff);
})->throws(ValidationException::class);

test('expired quotation is terminal', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Expired]);

    app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $this->staff);
})->throws(ValidationException::class);

test('draft cannot be declined directly', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    app(RecordQuotationDecision::class)->handle($quotation, 'declined', $this->staff);
})->throws(ValidationException::class);

test('draft cannot be expired directly', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    app(RecordQuotationDecision::class)->handle($quotation, 'expired', $this->staff);
})->throws(ValidationException::class);
