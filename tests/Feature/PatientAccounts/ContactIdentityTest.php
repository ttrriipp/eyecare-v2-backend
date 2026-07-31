<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\NormalizeContact;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Email Normalization ---

test('email is trimmed and lowercased', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->email('  Test@Example.COM  '))->toBe('test@example.com');
});

test('email rejects invalid format', function () {
    $normalize = app(NormalizeContact::class);

    $normalize->email('not-an-email');
})->throws(InvalidArgumentException::class);

test('email normalization is deterministic', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->email('Test@Example.COM'))
        ->toBe($normalize->email('test@example.com'))
        ->toBe($normalize->email('TEST@EXAMPLE.COM'));
});

// --- Phone Normalization ---

test('phone normalizes 09xxxxxxxxx to +63 format', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->phone('09171234567'))->toBe('+639171234567');
});

test('phone normalizes 63xxxxxxxxxx to +63 format', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->phone('639171234567'))->toBe('+639171234567');
});

test('phone normalizes 10-digit number without prefix', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->phone('9171234567'))->toBe('+639171234567');
});

test('phone rejects invalid numbers', function () {
    $normalize = app(NormalizeContact::class);

    $normalize->phone('123');
})->throws(InvalidArgumentException::class);

test('phone normalization is deterministic', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->phone('09171234567'))
        ->toBe($normalize->phone('639171234567'))
        ->toBe($normalize->phone('9171234567'));
});

// --- Name Normalization ---

test('name is trimmed, lowercased, and whitespace collapsed', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->name('  Ana   Reyes  '))->toBe('ana reyes');
});

test('name normalization is deterministic', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->name('ANA REYES'))
        ->toBe($normalize->name('ana reyes'))
        ->toBe($normalize->name('  Ana  Reyes  '));
});

// --- Date of Birth Normalization ---

test('date of birth is normalized to Y-m-d', function () {
    $normalize = app(NormalizeContact::class);

    expect($normalize->dateOfBirth('1990-05-15'))->toBe('1990-05-15');
});

// --- Blind Index (Lookup Hash) ---

test('email lookup hash is deterministic', function () {
    $hash = app(CreateContactLookupHash::class);

    expect($hash->forEmail('test@example.com'))
        ->toBe($hash->forEmail('test@example.com'));
});

test('email lookup hash normalizes before hashing', function () {
    $hash = app(CreateContactLookupHash::class);

    expect($hash->forEmail('Test@Example.COM'))
        ->toBe($hash->forEmail('test@example.com'));
});

test('different emails produce different hashes', function () {
    $hash = app(CreateContactLookupHash::class);

    expect($hash->forEmail('alice@example.com'))
        ->not->toBe($hash->forEmail('bob@example.com'));
});

test('phone lookup hash normalizes before hashing', function () {
    $hash = app(CreateContactLookupHash::class);

    expect($hash->forPhone('09171234567'))
        ->toBe($hash->forPhone('639171234567'));
});

test('name lookup hash normalizes before hashing', function () {
    $hash = app(CreateContactLookupHash::class);

    expect($hash->forName('Ana Reyes'))
        ->toBe($hash->forName('ana reyes'))
        ->toBe($hash->forName('ANA REYES'));
});

test('lookup hashes are 64-character hex strings', function () {
    $hash = app(CreateContactLookupHash::class);

    expect($hash->forEmail('test@example.com'))->toMatch('/^[a-f0-9]{64}$/')
        ->and($hash->forPhone('09171234567'))->toMatch('/^[a-f0-9]{64}$/')
        ->and($hash->forName('Ana Reyes'))->toMatch('/^[a-f0-9]{64}$/');
});

test('lookup hashes do not contain raw contact values', function () {
    $hash = app(CreateContactLookupHash::class);

    $emailHash = $hash->forEmail('test@example.com');
    expect($emailHash)->not->toContain('test@example.com');

    $phoneHash = $hash->forPhone('09171234567');
    expect($phoneHash)->not->toContain('09171234567');
});
