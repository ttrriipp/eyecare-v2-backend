<?php

/**
 * Tests for canonical contact-lens attribute validation.
 *
 * @see tasks/todo.md Task 31
 */

use App\Services\ContactLensAttributeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('contact-lens variants accept applicable power, base curve, diameter values', function () {
    $validator = new ContactLensAttributeValidator;

    $result = $validator->validate([
        'power' => '-3.00',
        'base_curve' => 8.6,
        'diameter' => 14.0,
    ]);

    expect($result['power'])->toBe('-3.00')
        ->and($result['base_curve'])->toBe(8.6)
        ->and($result['diameter'])->toBe(14.0);
});

test('contact-lens variants accept cylinder, axis, add values', function () {
    $validator = new ContactLensAttributeValidator;

    $result = $validator->validate([
        'cylinder' => '-1.25',
        'axis' => 180,
        'add' => '+2.00',
    ]);

    expect($result['cylinder'])->toBe('-1.25')
        ->and($result['axis'])->toBe(180)
        ->and($result['add'])->toBe('+2.00');
});

test('contact-lens variants accept color and pack_size', function () {
    $validator = new ContactLensAttributeValidator;

    $result = $validator->validate([
        'color' => 'Blue',
        'pack_size' => 6,
    ]);

    expect($result['color'])->toBe('Blue')
        ->and($result['pack_size'])->toBe(6);
});

test('invalid ranges fail validation', function () {
    $validator = new ContactLensAttributeValidator;

    $validator->validate([
        'base_curve' => 5, // below min 7
    ]);
})->throws(ValidationException::class);

test('axis must be 0-180', function () {
    $validator = new ContactLensAttributeValidator;

    $validator->validate([
        'axis' => 200, // above max 180
    ]);
})->throws(ValidationException::class);

test('non-canonical keys are filtered out', function () {
    $validator = new ContactLensAttributeValidator;

    $result = $validator->validate([
        'power' => '-3.00',
        'invalid_key' => 'should be ignored',
        'another_bad_key' => 123,
    ]);

    expect($result)->toHaveKey('power')
        ->and($result)->not->toHaveKey('invalid_key')
        ->and($result)->not->toHaveKey('another_bad_key');
});

test('existing valid attribute JSON remains readable', function () {
    $validator = new ContactLensAttributeValidator;

    $existing = [
        'power' => '-2.50',
        'base_curve' => 8.5,
        'diameter' => 14.2,
        'color' => 'Hazel',
    ];

    $result = $validator->validate($existing);

    expect($result)->toBe($existing);
});

test('frame attributes are unaffected by contact-lens validation', function () {
    $validator = new ContactLensAttributeValidator;

    expect($validator->isContactLens('frame'))->toBeFalse()
        ->and($validator->isContactLens('contact_lens'))->toBeTrue()
        ->and($validator->isContactLens('accessory'))->toBeFalse();
});

test('get applicable attributes filters correctly', function () {
    $validator = new ContactLensAttributeValidator;

    $result = $validator->getApplicableAttributes([
        'power' => '-3.00',
        'base_curve' => 8.6,
        'temple' => 140, // not a contact lens key
        'color' => null, // null value filtered
    ]);

    expect($result)->toHaveKeys(['power', 'base_curve'])
        ->and($result)->not->toHaveKey('temple')
        ->and($result)->not->toHaveKey('color');
});
