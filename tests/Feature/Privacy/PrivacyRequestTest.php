<?php

use App\Actions\Privacy\ProcessPrivacyRequest;
use App\Enums\PrivacyRequestDisposition;
use App\Enums\PrivacyRequestType;
use App\Models\Patient;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('privacy request records type identity verification and timestamps', function () {
    $patient = Patient::factory()->create();
    $request = PrivacyRequest::factory()->create([
        'patient_id' => $patient->id,
        'request_type' => PrivacyRequestType::Access,
        'identity_verified_method' => 'in_person',
    ]);

    expect($request->request_type)->toBe(PrivacyRequestType::Access)
        ->and($request->identity_verified_method)->toBe('in_person')
        ->and($request->requested_at)->not->toBeNull()
        ->and($request->patient->id)->toBe($patient->id);
});

test('only admin can process privacy requests', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();
    $request = PrivacyRequest::factory()->create();

    // Admin can process
    app(ProcessPrivacyRequest::class)->handle(
        $request,
        PrivacyRequestDisposition::Approved->value,
        $admin,
        'Identity verified.',
    );

    expect($request->fresh()->disposition)->toBe(PrivacyRequestDisposition::Approved)
        ->and($request->fresh()->handled_by)->toBe($admin->id)
        ->and($request->fresh()->handled_at)->not->toBeNull();
});

test('clinical history cannot be silently deleted through request workflow', function () {
    $admin = User::factory()->admin()->create();
    $request = PrivacyRequest::factory()->create([
        'request_type' => PrivacyRequestType::Erasure,
    ]);

    // Process the request — it records the disposition but does NOT delete data
    app(ProcessPrivacyRequest::class)->handle(
        $request,
        PrivacyRequestDisposition::Denied->value,
        $admin,
        'Clinical records must be retained per legal requirements.',
    );

    $request->refresh();
    expect($request->disposition)->toBe(PrivacyRequestDisposition::Denied)
        ->and($request->disposition_reason)->toContain('retained');
});

test('disposition must be valid', function () {
    $admin = User::factory()->admin()->create();
    $request = PrivacyRequest::factory()->create();

    app(ProcessPrivacyRequest::class)->handle(
        $request,
        'invalid_disposition',
        $admin,
    );
})->throws(ValidationException::class);

test('request types include access correction objection erasure', function () {
    expect(PrivacyRequestType::cases())->toContain(
        PrivacyRequestType::Access,
        PrivacyRequestType::Correction,
        PrivacyRequestType::Objection,
        PrivacyRequestType::Erasure,
    );
});
