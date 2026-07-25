<?php

use App\Actions\Quotations\PresentQuotation;
use App\Actions\Quotations\RecordQuotationDecision;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('presenting a draft quotation marks it as presented', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id, 'revision_number' => 1]);

    $result = app(PresentQuotation::class)->handle($quotation, $staff);

    expect($result->status)->toBe(QuotationStatus::Presented);
    expect($result->latestRevision->presented_by)->toBe($staff->id);
    expect($result->latestRevision->presented_at)->not->toBeNull();
});

test('cannot present a non-draft quotation', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);

    app(PresentQuotation::class)->handle($quotation, $staff);
})->throws(ValidationException::class);

test('accepting a presented quotation records decision', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id, 'revision_number' => 1]);

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $staff);

    expect($result->status)->toBe(QuotationStatus::Accepted);
    expect($result->latestRevision->accepted_by)->toBe($staff->id);
    expect($result->latestRevision->accepted_at)->not->toBeNull();
});

test('declining a presented quotation records decision', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'declined', $staff);

    expect($result->status)->toBe(QuotationStatus::Declined);
});

test('expiring a presented quotation records decision', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'expired', $staff);

    expect($result->status)->toBe(QuotationStatus::Expired);
});

test('cannot record decision on non-presented quotation', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $staff);
})->throws(ValidationException::class);

test('accepted quotations cannot be silently revised', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);

    // Attempting to present should fail
    $staff = User::factory()->staff()->create();
    app(PresentQuotation::class)->handle($quotation, $staff);
})->throws(ValidationException::class);

test('valid transitions are enforced', function () {
    $staff = User::factory()->staff()->create();

    // Draft → Presented (valid)
    $q1 = Quotation::factory()->create(['status' => QuotationStatus::Draft]);
    QuotationRevision::factory()->create(['quotation_id' => $q1->id]);
    app(PresentQuotation::class)->handle($q1, $staff);
    expect($q1->fresh()->status)->toBe(QuotationStatus::Presented);

    // Presented → Accepted (valid)
    $q2 = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    QuotationRevision::factory()->create(['quotation_id' => $q2->id]);
    app(RecordQuotationDecision::class)->handle($q2, 'accepted', $staff);
    expect($q2->fresh()->status)->toBe(QuotationStatus::Accepted);

    // Accepted → Presented (invalid)
    app(PresentQuotation::class)->handle($q2->fresh(), $staff);
})->throws(ValidationException::class);

test('acceptance identifies revision recorder and time', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
    ]);

    $result = app(RecordQuotationDecision::class)->handle($quotation, 'accepted', $staff);

    expect($result->latestRevision->accepted_by)->toBe($staff->id)
        ->and($result->latestRevision->accepted_at)->not->toBeNull()
        ->and($result->latestRevision->revision_number)->toBe(1);
});
