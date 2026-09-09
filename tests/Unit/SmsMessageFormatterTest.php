<?php

use App\Services\SmsMessageFormatter;

test('it prefixes unbranded messages with the EyeCare identity', function (): void {
    expect(SmsMessageFormatter::brand('Your appointment is confirmed.'))
        ->toBe('EyeCare: Your appointment is confirmed.');
});

test('it does not duplicate an existing EyeCare identity', function (): void {
    $message = 'EyeCare verification code: 123456.';

    expect(SmsMessageFormatter::brand($message))->toBe($message);
});
